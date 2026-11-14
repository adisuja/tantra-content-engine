<?php

declare(strict_types=1);

namespace App\Tantra\Digest;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Tantra\Links\CrmTracking;
use App\Tantra\Links\TrackedLink;
use App\Tantra\Mail\Kit;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * tantra: the numbers behind one person's daily digest, across every
 * workspace they belong to. Views are each post's latest views count (else
 * impressions, exposure or reach, whichever the network reports), summed;
 * "since yesterday" compares with the same sum as of yesterday's snapshots.
 */
final class DigestReport
{
    /** Views read from a snapshot: views, else impressions, else the network's exposure, else reach. */
    public const string VIEWS = 'COALESCE(s.views_count, s.impressions_count, s.exposure_count, s.reach_count, 0)';

    /** @param list<string> $workspaceIds */
    public function __construct(public readonly User $user, public readonly array $workspaceIds, public readonly CarbonImmutable $now) {}

    public static function for(User $user, ?CarbonImmutable $now = null): self
    {
        $ids = DB::table('user_workspace')->where('user_id', $user->id)->pluck('workspace_id')->map(fn ($id): string => (string) $id)->all();

        return new self($user, $ids, $now ?? CarbonImmutable::now());
    }

    /** @return Collection<int, ReviewItem> items waiting for this person, in posting order */
    public function waitingForReview(): Collection
    {
        return ReviewItem::query()->whereIn('workspace_id', $this->workspaceIds)
            ->where('review_status', ReviewStatus::InReview->value)
            ->with('workspace.account')
            ->orderBy('planned_date')->orderBy('planned_time')
            ->get()
            ->filter(fn (ReviewItem $item): bool => ReviewGate::reviewOwnerId($item) === $this->user->id)
            ->values();
    }

    public function publishedSince(?CarbonImmutable $since): int
    {
        return Post::query()->whereIn('workspace_id', $this->workspaceIds)
            ->where('status', PostStatus::Published->value)
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
            ->count();
    }

    public function scheduledNextDays(int $days): int
    {
        return Post::query()->whereIn('workspace_id', $this->workspaceIds)
            ->where('status', PostStatus::Scheduled->value)
            ->whereBetween('scheduled_at', [$this->now, $this->now->addDays($days)])
            ->count();
    }

    /** @return array<string, int> views per network as of the end of that day, highest first */
    public function viewsByNetwork(?CarbonImmutable $asOf = null): array
    {
        if ($this->workspaceIds === []) {
            return [];
        }
        $day = ($asOf ?? $this->now)->toDateString();

        return DB::table('analytics_publications as p')
            ->join('analytics_publication_daily_snapshots as s', 's.publication_id', '=', 'p.id')
            ->whereIn('p.workspace_id', $this->workspaceIds)
            ->whereRaw('s.date = (select max(s2.date) from analytics_publication_daily_snapshots s2 where s2.publication_id = p.id and s2.date <= ?)', [$day])
            ->groupBy('p.network')
            ->selectRaw('p.network as network, SUM('.self::VIEWS.') as views')
            ->get()
            ->mapWithKeys(fn (object $row): array => [Kit::networkOf((string) $row->network) => (int) $row->views])
            ->filter(fn (int $views): bool => $views > 0)
            ->sortDesc()
            ->all();
    }

    /** @return array{post: object|null, views: int} the most viewed post published in the last 30 days */
    public function topPost(): array
    {
        if ($this->workspaceIds === []) {
            return ['post' => null, 'views' => 0];
        }
        $row = DB::table('analytics_publications as p')
            ->join('analytics_publication_daily_snapshots as s', 's.publication_id', '=', 'p.id')
            ->whereIn('p.workspace_id', $this->workspaceIds)
            ->where('p.provider_published_at', '>=', $this->now->subDays(30))
            ->whereRaw('s.date = (select max(s2.date) from analytics_publication_daily_snapshots s2 where s2.publication_id = p.id)')
            ->selectRaw('p.network as network, p.excerpt as excerpt, p.permalink as permalink, '.self::VIEWS.' as views')
            ->orderByDesc('views')
            ->first();

        return ['post' => $row, 'views' => (int) ($row->views ?? 0)];
    }

    /** Human clicks (the CRM removes bots) on the tracked links in these workspaces' posts; null when the CRM is not linked. */
    public function humanClicks(): ?int
    {
        if (! CrmTracking::configured()) {
            return null;
        }
        $ids = TrackedLink::query()->whereIn('workspace_id', $this->workspaceIds)->whereNotNull('crm_link_id')->pluck('crm_link_id')->all();
        if ($ids === []) {
            return null;
        }
        try {
            $clicks = app(CrmTracking::class)->linkClicks();
        } catch (Throwable) {
            return null;
        }

        return array_sum(array_map(fn (string $id): int => (int) ($clicks[$id]['human'] ?? 0), $ids));
    }

    /**
     * The one number worth putting in bold, from real data only. Clicks to the
     * site beat views, views beat a count of posts. Each comes with its math.
     *
     * @return array{value: string, label: string, math: string}|null
     */
    public function standout(int $publishedAllTime): ?array
    {
        $clicks = $this->humanClicks();
        if ($clicks !== null && $clicks > 0) {
            return ['value' => Kit::compact($clicks), 'label' => $clicks === 1 ? 'person clicked from your posts to your site' : 'people clicked from your posts to your site',
                'math' => 'Clicks on the tracked links in your posts, all time, counted by the Tantra CRM with bots removed.'];
        }

        $top = $this->topPost();
        if ($top['views'] > 0 && $top['post'] !== null) {
            $excerpt = trim((string) $top['post']->excerpt);

            return ['value' => Kit::compact($top['views']), 'label' => 'views on your best post this month',
                'math' => 'The '.Kit::networkLabel((string) $top['post']->network).' post'.($excerpt !== '' ? ' "'.Kit::clamp($excerpt, 70)[0].'"' : '').', as the network reports it today.'];
        }

        if ($publishedAllTime > 0) {
            return ['value' => number_format($publishedAllTime), 'label' => $publishedAllTime === 1 ? 'post published for you' : 'posts published for you',
                'math' => 'Every post the Engine has published to your channels so far.'];
        }

        return null;
    }
}
