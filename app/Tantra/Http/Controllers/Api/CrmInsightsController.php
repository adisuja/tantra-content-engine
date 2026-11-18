<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Crm\CrmContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * tantra: the Tantra app's Content > Insights, server to server and read only (tantra-crm docs/crm/engine-patches/p4-c07b/README.md).
 * Shared key (EnsureCrmKey); the Tantra team id maps to Engine workspaces and an unmapped team gets nothing. Numbers come from the
 * same analytics the Engine's Insights page reads (the latest daily snapshot of each post's publication). A network that does not
 * report a number gives null, never 0. No token, meta or workspace id leaves here.
 *
 *   POST insights   totals, per network, per connected profile (AF-09: one view of every profile) and the best posts of the last 7, 30 or 90 days
 */
class CrmInsightsController extends Controller
{
    private const CACHE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'days' => ['required', 'integer', 'in:7,30,90'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $days = (int) $data['days'];

        $answer = $ids === []
            ? self::empty()
            : Cache::remember('tantra:crm-insights:'.sha1($data['workspace'].'|'.implode(',', $ids).'|'.$days), self::CACHE_SECONDS, fn (): array => $this->build($ids, $days));

        return response()->json(['data' => $answer])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private static function empty(): array
    {
        return ['totals' => ['posts' => null, 'published' => null, 'scheduled' => null, 'failed' => null, 'impressions' => null, 'engagements' => null], 'by_network' => [], 'by_profile' => [], 'top' => [], 'updated_at' => null];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private function build(array $ids, int $days): array
    {
        $end = now()->utc();
        $start = $end->copy()->subDays($days);

        $published = Post::query()->whereIn('workspace_id', $ids)->where('status', PostStatus::Published->value)
            ->whereBetween('published_at', [$start, $end])->with('socialAccount')->get();
        $scheduled = Post::query()->whereIn('workspace_id', $ids)->where('status', PostStatus::Scheduled->value)->whereBetween('scheduled_at', [$start, $end])->count();
        $failed = Post::query()->whereIn('workspace_id', $ids)->where('status', PostStatus::Failed->value)->whereBetween('updated_at', [$start, $end])->count();

        $metrics = $this->metrics($published->pluck('id')->all());
        $rows = $published->map(function (Post $post) use ($metrics): array {
            $m = $metrics[$post->id] ?? ['impressions' => null, 'engagements' => null];

            return [
                'id' => (string) $post->id,
                'account' => $post->social_account_id === null ? null : (string) $post->social_account_id,
                'platform' => $post->platform?->value ?? 'unknown',
                'content' => (string) $post->content,
                'display_name' => $post->display_name,
                'published_at' => $post->published_at?->utc()->toIso8601String(),
                'impressions' => $m['impressions'],
                'engagements' => $m['engagements'],
                'platform_url' => self::https($post->platform_url),
            ];
        });

        $byNetwork = $rows->groupBy('platform')->map(fn (Collection $group, string $platform): array => [
            'platform' => $platform,
            'posts' => $group->count(),
            'impressions' => self::sum($group->pluck('impressions')),
            'engagements' => self::sum($group->pluck('engagements')),
        ])->sortByDesc('posts')->values()->all();

        $byProfile = $this->profiles($ids, $rows);
        $rows = $rows->map(fn (array $r): array => array_diff_key($r, ['account' => true]));

        $top = $rows->sort(fn (array $a, array $b): int => [$b['engagements'] ?? -1, $b['impressions'] ?? -1] <=> [$a['engagements'] ?? -1, $a['impressions'] ?? -1])
            ->take(10)->values()->all();

        return [
            'totals' => [
                'posts' => $rows->count() + $scheduled + $failed,
                'published' => $rows->count(),
                'scheduled' => $scheduled,
                'failed' => $failed,
                'impressions' => self::sum($rows->pluck('impressions')),
                'engagements' => self::sum($rows->pluck('engagements')),
            ],
            'by_network' => $byNetwork,
            'by_profile' => $byProfile,
            'top' => $top,
            'updated_at' => $end->toIso8601String(),
        ];
    }

    /**
     * AF-09: every profile connected to the team's workspaces with its numbers for the period, the busiest first. A profile
     * with no post in the period still shows (posts 0, numbers null). The account's own id never leaves here.
     *
     * @param  list<string>  $ids
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function profiles(array $ids, Collection $rows): array
    {
        $accounts = SocialAccount::query()->whereIn('workspace_id', $ids)->orderBy('created_at')->limit(200)->get();
        $byAccount = $rows->groupBy(fn (array $r): string => (string) ($r['account'] ?? ''));

        return $accounts->map(function (SocialAccount $a) use ($byAccount): array {
            $group = $byAccount->get((string) $a->id, new Collection);

            return [
                'platform' => $a->platform?->value ?? 'unknown',
                'display_name' => $a->display_name ?: $a->username,
                'username' => $a->username,
                'avatar' => self::https($a->avatar_url),
                'connected' => $a->status === AccountStatus::Connected,
                'posts' => $group->count(),
                'impressions' => self::sum($group->pluck('impressions')),
                'engagements' => self::sum($group->pluck('engagements')),
            ];
        })->sortByDesc(fn (array $p): array => [$p['posts'], $p['impressions'] ?? -1])->values()->all();
    }

    /**
     * The latest daily snapshot of each post's publication: impressions, and engagements (the stored engagement count, else the sum
     * of reactions, comments and shares). Null when the network reports nothing.
     *
     * @param  list<string>  $postIds
     * @return array<string, array{impressions: ?int, engagements: ?int}>
     */
    private function metrics(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($postIds, 500) as $chunk) {
            $latest = DB::table('analytics_publication_daily_snapshots as d')
                ->join('analytics_publications as p', 'p.id', '=', 'd.publication_id')
                ->whereIn('p.post_id', $chunk)
                ->selectRaw('d.publication_id, MAX(d.date) as latest_date')->groupBy('d.publication_id');
            $rows = DB::table('analytics_publications as p')
                ->joinSub($latest, 'l', 'l.publication_id', '=', 'p.id')
                ->join('analytics_publication_daily_snapshots as s', fn ($j) => $j->on('s.publication_id', '=', 'p.id')->on('s.date', '=', 'l.latest_date'))
                ->whereIn('p.post_id', $chunk)
                ->get(['p.post_id', 's.impressions_count', 's.engagement_count', 's.reactions_count', 's.comments_count', 's.shares_count']);
            foreach ($rows as $row) {
                $parts = [$row->reactions_count, $row->comments_count, $row->shares_count];
                $known = array_filter($parts, fn ($v): bool => $v !== null);
                $engagements = $row->engagement_count !== null ? (int) $row->engagement_count : ($known === [] ? null : (int) array_sum($known));
                $out[$row->post_id] = ['impressions' => $row->impressions_count === null ? null : (int) $row->impressions_count, 'engagements' => $engagements];
            }
        }

        return $out;
    }

    /** @param Collection<int, mixed> $values */
    private static function sum(Collection $values): ?int
    {
        $known = $values->filter(fn ($v): bool => $v !== null);

        return $known->isEmpty() ? null : (int) $known->sum();
    }

    private static function https(?string $url): ?string
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}
