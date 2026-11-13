<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\BoostRun;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Collection;

/**
 * tantra: the team dashboard (PRD 10.6). Per person and platform, over 7, 30 or
 * 90 days: impressions, our engagement rate, clicks, followers gained, boost
 * coverage, the best and worst posts, and the winning formula card.
 *
 * Personal posts have no impressions (Spike S2), so posts are ranked by
 * engagement (reactions + comments + shares), the one number every post has.
 */
final class Insights
{
    public const RANGES = [7, 30, 90];

    /** Batches the winning formula looks back over, and the sample below which it says "weak signal". */
    public const FORMULA_BATCHES = 3;

    public const FORMULA_MIN_POSTS = 3;

    /**
     * @param  list<string>  $workspaceIds
     * @return array<string, mixed>
     */
    public static function for(array $workspaceIds, int $days): array
    {
        $profiles = WorkspaceSettings::query()->whereIn('workspace_id', $workspaceIds)->pluck('brand_profile_key', 'workspace_id');
        $posts = self::posts($workspaceIds, now()->subDays($days), $profiles);

        $people = $posts->groupBy(fn (array $p): string => $p['profile'].'|'.$p['platform'])
            ->map(fn (Collection $group): array => self::person($group))
            ->sortByDesc('engagement')
            ->values();

        $pageDays = AccountSnapshot::query()
            ->whereHas('account', fn ($q) => $q->whereIn('workspace_id', $workspaceIds))
            ->whereDate('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        return [
            'days' => $days,
            'people' => $people->all(),
            'page' => [
                'followers_gained' => $pageDays->isEmpty() ? null : (int) $pageDays->sum('follower_gain'),
                'page_views' => $pageDays->isEmpty() ? null : (int) $pageDays->sum('page_views'),
            ],
            'best' => $posts->sortByDesc('engagement')->take(3)->values()->all(),
            'worst' => $posts->count() > 3 ? $posts->sortBy('engagement')->take(3)->values()->all() : [],
            'formula' => self::formula($workspaceIds, $profiles),
        ];
    }

    /**
     * Published LinkedIn posts with their latest reading and their review item.
     *
     * @param  list<string>  $workspaceIds
     * @param  Collection<string, string>  $profiles
     * @return Collection<int, array<string, mixed>>
     */
    private static function posts(array $workspaceIds, mixed $since, Collection $profiles): Collection
    {
        $posts = Post::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', PostStatus::Published)
            ->whereIn('platform', [Platform::LinkedIn, Platform::UnipileLinkedIn, Platform::ZernioLinkedInPage])
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
            ->get();
        if ($posts->isEmpty()) {
            return collect();
        }

        $latest = MetricSnapshot::query()->whereIn('post_id', $posts->modelKeys())->orderBy('captured_at')->get()->keyBy('post_id');
        $items = ReviewItem::query()->whereIn('post_group_id', $posts->pluck('post_group_id')->filter()->unique())->get()->keyBy('post_group_id');
        $boosted = self::boosted($posts->modelKeys());

        return $posts->map(function (Post $post) use ($latest, $items, $profiles, $boosted): array {
            $snap = $latest[$post->id] ?? null;
            $item = $post->post_group_id ? ($items[$post->post_group_id] ?? null) : null;
            $engagement = $snap ? (int) $snap->reactions + (int) $snap->comments + (int) $snap->shares : null;

            return [
                'post_id' => $post->id,
                'key' => $item?->engine_post_id,
                'batch' => $item?->engine_batch_id,
                'profile' => (string) ($profiles[$post->workspace_id] ?? 'unmapped'),
                'platform' => $post->platform === Platform::ZernioLinkedInPage ? 'linkedin_page' : 'linkedin',
                'format' => $item?->format,
                'pillar' => $item?->pillar,
                'sample' => $item?->sample_id,
                'short' => $item?->short ?: mb_substr(trim((string) $post->content), 0, 80),
                'url' => $post->platform_url,
                'published_at' => $post->published_at?->toIso8601ZuluString(),
                'impressions' => $snap?->impressions,
                'clicks' => $snap?->clicks,
                'reactions' => $snap?->reactions,
                'comments' => $snap?->comments,
                'shares' => $snap?->shares,
                'engagement' => $engagement,
                'engagement_rate' => $snap?->engagement_rate,
                'boosted' => $boosted[$post->id] ?? false,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private static function person(Collection $group): array
    {
        $first = $group->first();
        $withImpressions = $group->filter(fn (array $p): bool => $p['impressions'] !== null);
        $impressions = $withImpressions->sum('impressions');

        return [
            'profile' => $first['profile'],
            'platform' => $first['platform'],
            'posts' => $group->count(),
            'impressions' => $withImpressions->isEmpty() ? null : (int) $impressions,
            'clicks' => $group->whereNotNull('clicks')->isEmpty() ? null : (int) $group->sum('clicks'),
            'engagement' => (int) $group->sum('engagement'),
            // Ours, labeled as ours: engagements over impressions on the posts that have impressions.
            'engagement_rate' => $impressions > 0
                ? round($withImpressions->sum(fn (array $p): int => (int) $p['engagement']) / $impressions, 4)
                : null,
            'boost_coverage' => round($group->where('boosted', true)->count() / max(1, $group->count()), 2),
        ];
    }

    /**
     * Posts that got at least 3 teammate comments in their first hour.
     *
     * @param  list<string>  $postIds
     * @return array<string, bool>
     */
    private static function boosted(array $postIds): array
    {
        $runs = BoostRun::query()->whereIn('post_id', $postIds)->get(['id', 'post_id', 'published_at', 'window_start_at']);
        if ($runs->isEmpty()) {
            return [];
        }

        $comments = BoostAction::query()->whereIn('run_id', $runs->modelKeys())->where('kind', 'comment')->where('status', 'posted')
            ->get(['run_id', 'posted_at'])->groupBy('run_id');

        return $runs->mapWithKeys(function (BoostRun $run) use ($comments): array {
            $start = $run->published_at ?? $run->window_start_at;
            $inHourOne = collect($comments[$run->id] ?? [])->filter(fn (BoostAction $a): bool => $start !== null && $a->posted_at !== null && $a->posted_at->lte($start->copy()->addHour()))->count();

            return [$run->post_id => $inHourOne >= 3];
        })->all();
    }

    /**
     * Per person: the formats and samples that beat their own median engagement
     * by the widest margin over their last 3 batches.
     *
     * @param  list<string>  $workspaceIds
     * @param  Collection<string, string>  $profiles
     * @return list<array<string, mixed>>
     */
    private static function formula(array $workspaceIds, Collection $profiles): array
    {
        $posts = self::posts($workspaceIds, null, $profiles)->filter(fn (array $p): bool => $p['engagement'] !== null && $p['batch'] !== null);

        return $posts->groupBy('profile')->map(function (Collection $mine, string $profile): ?array {
            $batches = $mine->pluck('batch')->unique()->sortByDesc(fn ($b) => is_numeric($b) ? (int) $b : $b)->take(self::FORMULA_BATCHES)->values();
            $recent = $mine->whereIn('batch', $batches->all());
            $median = self::median($recent->pluck('engagement'));
            if ($recent->isEmpty() || $median <= 0) {
                return null;
            }

            $lifts = [];
            foreach (['format', 'sample'] as $field) {
                foreach ($recent->groupBy(fn (array $p): string => (string) ($p[$field] ?? ''))->except(['']) as $value => $group) {
                    $lifts[] = [
                        'kind' => $field,
                        'value' => (string) $value,
                        'posts' => $group->count(),
                        'median' => self::median($group->pluck('engagement')),
                        'lift' => round(self::median($group->pluck('engagement')) / $median, 2),
                    ];
                }
            }
            $winners = collect($lifts)->filter(fn (array $l): bool => $l['lift'] > 1)->sortByDesc('lift')->take(3)->values();

            return [
                'profile' => $profile,
                'batches' => $batches->all(),
                'posts' => $recent->count(),
                'median_engagement' => $median,
                'winners' => $winners->all(),
                // The existing rule: a handful of posts is a weak signal; keep the pillar mix until a pattern holds for 3 batches.
                'weak_signal' => $recent->count() < self::FORMULA_MIN_POSTS * 2 || $batches->count() < self::FORMULA_BATCHES
                    || $winners->contains(fn (array $w): bool => $w['posts'] < self::FORMULA_MIN_POSTS),
            ];
        })->filter()->values()->all();
    }

    /** @param Collection<int, mixed> $values */
    private static function median(Collection $values): float
    {
        $sorted = $values->map(fn ($v): float => (float) $v)->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 === 1 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }
}
