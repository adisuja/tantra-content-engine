<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Actions\Analytics\BuildPublicationAnalyticsReport;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\DateRange;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\RequestIds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Insights tab across every workspace the viewer can see (PRD 26): the people,
 * network and channel filters, the Top 5 under each sort, the posts table, and one
 * row per channel with its followers and, when its numbers cannot be read, why.
 * The upstream report itself is built by upstream's readers under {@see InsightsScope}.
 */
final class AccountInsights
{
    /** The Top 5 sorts, in the order the switch shows them. */
    public const array TOP_SORTS = ['engagement', 'impressions', 'reactions', 'comments', 'shares', 'engagement_rate'];

    /** The posts table's sortable columns. */
    public const array POST_SORTS = ['published_at', ...self::TOP_SORTS];

    public const int POSTS_PAGE_SIZE = 10;

    /** Reactions, comments and shares when the network gives no engagement count of its own. */
    private const string ENGAGEMENT = 'COALESCE(metric.engagement_count, CASE WHEN metric.reactions_count IS NULL AND metric.comments_count IS NULL AND metric.shares_count IS NULL THEN NULL ELSE COALESCE(metric.reactions_count, 0) + COALESCE(metric.comments_count, 0) + COALESCE(metric.shares_count, 0) END)';

    private const string IMPRESSIONS = 'COALESCE(metric.impressions_count, metric.exposure_count, metric.views_count)';

    private const string EXPOSURE = 'COALESCE(metric.exposure_count, metric.impressions_count, metric.reach_count, metric.views_count)';

    /** Failure categories a read keeps trying on its own. */
    private const array RETRYING = ['queue_failed', 'transient', 'rate_limited'];

    /** Partial reads the network limited. */
    private const array LIMITED = ['provider_limited', 'x_timeline_limit'];

    /** Categories with a plain reason of their own; any other failure reads as `failed`. */
    private const array REASONS = ['plan_required', 'permission', 'authentication', 'malformed', 'unsupported'];

    /** @var list<string> */
    private array $workspaceIds = [];

    /** @var list<string> */
    private array $people = [];

    /** @var list<string> */
    private array $networks = [];

    /** @var list<string> */
    private array $picked = [];

    private bool $narrowedToNothing = false;

    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKeys,
        private readonly BuildPublicationAnalyticsReport $publications,
    ) {}

    /** Scopes this request's Insights reads to every workspace the viewer can see, and reads the filters. */
    public function begin(Request $request): self
    {
        $this->workspaceIds = InsightsScope::visibleTo($request->user());
        InsightsScope::use($this->workspaceIds);

        $this->people = array_values(array_intersect(RequestIds::uuidList($request->collect('workspaces')), $this->workspaceIds));
        $networks = collect(Platform::analyticsValues())->map(fn (string $value): string => Platform::from($value)->network())->unique();
        $this->networks = $request->collect('networks')->filter(fn (mixed $network): bool => is_string($network) && $networks->contains($network))->unique()->values()->all();
        $this->picked = RequestIds::uuidList($request->collect('channels'));

        return $this;
    }

    /**
     * The channel ids handed to upstream's channel filter: the picked channels, narrowed
     * to the picked people and networks when either is set.
     *
     * @param  Collection<int, mixed>  $requested
     * @return Collection<int, mixed>
     */
    public function requested(Collection $requested): Collection
    {
        if ($this->people === [] && $this->networks === []) {
            return $requested;
        }

        $ids = SocialAccount::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->includedInAnalytics()
            ->get(['id', 'workspace_id', 'platform'])
            ->filter(fn (SocialAccount $channel): bool => $this->matches($channel))
            ->pluck('id')
            ->when($this->picked !== [], fn (Collection $ids): Collection => $ids->intersect($this->picked))
            ->values();
        $this->narrowedToNothing = $ids->isEmpty();

        return $ids;
    }

    /**
     * Upstream reads every channel when nothing is selected, so filters that leave no
     * channel select none explicitly.
     *
     * @param  array<string, string>|null  $channelKeys
     * @return array<string, string>|null
     */
    public function keys(?array $channelKeys): ?array
    {
        return $this->narrowedToNothing ? [] : $channelKeys;
    }

    /**
     * The channels the person picked themselves, among the channels they may see: upstream's
     * selection while no people or network filter is set, else the picks it would not narrow.
     *
     * @param  list<string>  $selected
     * @param  list<array{id: string}>  $options
     * @return list<string>
     */
    public function pickedChannels(array $selected, array $options): array
    {
        if ($this->people === [] && $this->networks === []) {
            return $this->picked = $selected;
        }

        return $this->picked = array_values(array_intersect($this->picked, array_column($options, 'id')));
    }

    public function filtered(): bool
    {
        return $this->people !== [] || $this->networks !== [] || $this->picked !== [];
    }

    /**
     * @param  array<string, mixed>  $report  The upstream report built for the page.
     * @param  array<string, string>|null  $channelKeys
     * @return array<string, mixed>
     */
    public function props(Workspace $workspace, User $user, array $report, ?array $channelKeys, Request $request): array
    {
        $range = new DateRange(
            CarbonImmutable::parse((string) data_get($report, 'range.start'), 'UTC'),
            CarbonImmutable::parse((string) data_get($report, 'range.end'), 'UTC'),
            timezone: $user->timezone,
        );
        $accountKeys = $channelKeys === null ? null : array_values(array_unique($channelKeys));
        $workspaces = Workspace::query()->whereIn('id', $this->workspaceIds)->orderBy('name')->get(['id', 'name']);
        $channels = $this->channels($workspaces, $range, $report);
        $byKey = collect($channels)->filter(fn (array $channel): bool => $channel['analytics_key'] !== null)->keyBy('analytics_key');

        return [
            'workspaces' => $workspaces->map(fn (Workspace $space): array => ['id' => $space->id, 'name' => $space->name])->values()->all(),
            'networks' => collect($channels)
                ->filter(fn (array $channel): bool => $channel['analytics_key'] !== null)
                ->map(fn (array $channel): string => $channel['network'])
                ->unique()->sort()->values()
                ->map(fn (string $network): array => ['value' => $network, 'platform' => (Platform::tryFrom($network) ?? Platform::LinkedIn)->value])
                ->all(),
            'channels' => array_values(array_filter($channels, fn (array $channel): bool => $this->shows($channel))),
            'filters' => ['workspaces' => $this->people, 'networks' => $this->networks, 'channels' => $this->picked],
            'filtered' => $this->filtered(),
            'top_posts' => $this->topPosts($workspace, $range, $accountKeys, $byKey),
            'posts' => $this->posts($workspace, $range, $accountKeys, $byKey, $request),
            'sorts' => ['top' => self::TOP_SORTS, 'posts' => self::POST_SORTS],
        ];
    }

    private function matches(SocialAccount $channel): bool
    {
        return ($this->people === [] || in_array($channel->workspace_id, $this->people, true))
            && ($this->networks === [] || in_array($channel->platform->network(), $this->networks, true));
    }

    /** @param  array<string, mixed>  $channel */
    private function shows(array $channel): bool
    {
        return ($this->people === [] || in_array($channel['workspace_id'], $this->people, true))
            && ($this->networks === [] || in_array($channel['network'], $this->networks, true))
            && ($this->picked === [] || in_array($channel['id'], $this->picked, true));
    }

    /**
     * Every channel of the visible workspaces: its person, latest followers, the change in
     * the period, its posts in the period and, when its numbers cannot be read, why.
     *
     * @param  EloquentCollection<int, Workspace>  $workspaces
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function channels(EloquentCollection $workspaces, DateRange $range, array $report): array
    {
        $accounts = SocialAccount::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->orderBy('position')
            ->get();
        $measured = $accounts->filter(fn (SocialAccount $channel): bool => $channel->platform->isIncludedInAnalytics());
        $keys = $this->accountKeys->forMany($measured->values());
        $names = $workspaces->pluck('name', 'id');
        $states = AnalyticsSyncState::query()->whereIn('social_account_id', $accounts->modelKeys())->get()->groupBy('social_account_id');
        $failures = AnalyticsPublication::query()
            ->whereIn('social_account_id', $accounts->modelKeys())
            ->where('metric_failures', '>', 0)
            ->groupBy('social_account_id')
            ->selectRaw('social_account_id, COUNT(*) as failing')
            ->pluck('failing', 'social_account_id');
        $followers = $this->latestFollowers(array_values($keys), $range);
        $published = DB::table('analytics_publications')
            ->whereIn('workspace_id', $this->workspaceIds)
            ->whereIn('social_account_key', array_values($keys))
            ->groupBy('social_account_key')
            ->selectRaw('social_account_key, COUNT(*) as total')
            ->pluck('total', 'social_account_key');
        $changes = collect((array) data_get($report, 'followers.accounts', []))->keyBy('social_account_key');
        $posts = collect((array) data_get($report, 'performance', []))->keyBy('social_account_key');

        return $accounts
            ->sortBy(fn (SocialAccount $channel): string => mb_strtolower((string) $names->get($channel->workspace_id)).'|'.str_pad((string) $channel->position, 6, '0', STR_PAD_LEFT))
            ->map(function (SocialAccount $channel) use ($keys, $names, $states, $failures, $followers, $published, $changes, $posts): array {
                $key = $keys[$channel->id] ?? null;
                $latest = $key === null ? null : $followers->get($key);

                return [
                    'id' => $channel->id,
                    'workspace_id' => $channel->workspace_id,
                    'person' => $names->get($channel->workspace_id),
                    'platform' => $channel->platform->value,
                    'network' => $channel->platform->network(),
                    'display_label' => $channel->display_label,
                    'username' => $channel->username,
                    'avatar_url' => $channel->avatar_url,
                    'status' => $channel->status->value,
                    'verified_badge' => $channel->verified_badge,
                    'analytics_key' => $key,
                    'followers' => $latest === null ? null : [
                        'value' => (int) $latest->followers_count,
                        'date' => substr((string) $latest->date, 0, 10),
                        'change' => $key === null ? null : data_get($changes->get($key), 'net'),
                    ],
                    'posts' => $key === null ? null : data_get($posts->get($key), 'posts.value', 0),
                    'notices' => $this->notices(
                        $channel,
                        $states->get($channel->id, collect()),
                        (int) $failures->get($channel->id, 0),
                        $latest !== null || ($key !== null && (int) $published->get($key, 0) > 0),
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Each key's latest follower count up to the period's end (a period that reaches
     * today takes today's reading, whatever its UTC date).
     *
     * @param  list<string>  $keys
     * @return Collection<string, object>
     */
    private function latestFollowers(array $keys, DateRange $range): Collection
    {
        if ($keys === []) {
            return collect();
        }

        $today = CarbonImmutable::now($range->timezone)->toDateString();
        $until = $range->end->toDateString() >= $today ? null : $range->end->toDateString();
        $scoped = fn (): Builder => DB::table('analytics_account_daily_snapshots')
            ->whereIn('analytics_account_daily_snapshots.workspace_id', $this->workspaceIds)
            ->whereIn('analytics_account_daily_snapshots.social_account_key', $keys)
            ->whereNotNull('analytics_account_daily_snapshots.followers_count')
            ->when($until !== null, fn (Builder $query): Builder => $query->where('analytics_account_daily_snapshots.date', '<=', $until));
        $latest = $scoped()
            ->select('analytics_account_daily_snapshots.social_account_key')
            ->selectRaw('MAX(analytics_account_daily_snapshots.date) as latest_date')
            ->groupBy('analytics_account_daily_snapshots.social_account_key');

        return $scoped()
            ->joinSub($latest, 'latest', fn (JoinClause $join): JoinClause => $join
                ->on('analytics_account_daily_snapshots.social_account_key', '=', 'latest.social_account_key')
                ->on('analytics_account_daily_snapshots.date', '=', 'latest.latest_date'))
            ->get([
                'analytics_account_daily_snapshots.social_account_key',
                'analytics_account_daily_snapshots.date',
                'analytics_account_daily_snapshots.followers_count',
            ])
            ->keyBy('social_account_key');
    }

    /**
     * Why a channel's numbers are missing or partial, in the order a person can act on them.
     *
     * @param  Collection<int, AnalyticsSyncState>  $states
     * @return list<array{key: string, count?: int}>
     */
    private function notices(SocialAccount $channel, Collection $states, int $failingPosts, bool $hasData): array
    {
        if ($channel->status !== Status::Connected) {
            return [['key' => 'disconnected']];
        }

        if (! $channel->platform->isIncludedInAnalytics()) {
            return [['key' => 'not_covered']];
        }

        $notices = [];
        $backfill = $states->first(fn (AnalyticsSyncState $state): bool => $state->collector === SyncCollector::PublicationBackfill);
        $failed = $states
            ->sortBy(fn (AnalyticsSyncState $state): int => $state->collector === SyncCollector::PublicationBackfill ? 0 : 1)
            ->first(fn (AnalyticsSyncState $state): bool => $state->status === SyncStatus::Failed);
        $category = (string) $failed?->last_error_category;

        if ($failed !== null) {
            $notices[] = ['key' => match (true) {
                in_array($category, self::REASONS, true) => $category,
                in_array($category, self::RETRYING, true) => 'retrying',
                default => 'failed',
            }];
        } elseif ($states->contains(fn (AnalyticsSyncState $state): bool => $state->status === SyncStatus::ProviderLimited
            || in_array($state->last_error_category, self::LIMITED, true))) {
            $notices[] = ['key' => 'limited'];
        }

        if ($failingPosts > 0) {
            $notices[] = ['key' => 'metric_failures', 'count' => $failingPosts];
        }

        if ($channel->platform === Platform::ZernioLinkedInPage) {
            $notices[] = ['key' => 'engine_posts_only'];
        }

        if ($notices === [] && ! $hasData && ($backfill === null || ! $backfill->isTerminal())) {
            $notices[] = ['key' => 'waiting'];
        }

        return $notices;
    }

    /**
     * The Top 5 under each sort, across every selected channel.
     *
     * @param  list<string>|null  $accountKeys
     * @param  Collection<string, array<string, mixed>>  $channels
     * @return array<string, list<array<string, mixed>>>
     */
    private function topPosts(Workspace $workspace, DateRange $range, ?array $accountKeys, Collection $channels): array
    {
        $lists = [];

        foreach (self::TOP_SORTS as $sort) {
            $expression = $this->expression($sort);
            $lists[$sort] = $this->base($workspace, $range, $accountKeys)
                ->whereRaw("({$expression}) IS NOT NULL")
                ->orderByRaw("({$expression}) DESC")
                ->orderByDesc('publication.provider_published_at')
                ->orderBy('publication.id')
                ->limit(5)
                ->get()
                ->all();
        }

        $media = $this->media(collect($lists)->flatten(1));

        return array_map(fn (array $rows): array => array_values(array_map(
            fn (object $row, int $index): array => $this->row($row, $index + 1, $workspace, $channels, $media),
            $rows,
            array_keys($rows),
        )), $lists);
    }

    /**
     * Every post of the period across the selected channels, external ones included.
     *
     * @param  list<string>|null  $accountKeys
     * @param  Collection<string, array<string, mixed>>  $channels
     * @return array<string, mixed>
     */
    private function posts(Workspace $workspace, DateRange $range, ?array $accountKeys, Collection $channels, Request $request): array
    {
        $sort = in_array($request->query('posts_sort'), self::POST_SORTS, true) ? (string) $request->query('posts_sort') : 'published_at';
        $direction = $request->query('posts_dir') === 'asc' ? 'asc' : 'desc';
        $expression = $this->expression($sort);
        $page = max(1, (int) $request->query('posts_page', 1));

        $paginator = $this->base($workspace, $range, $accountKeys)
            ->orderByRaw("CASE WHEN ({$expression}) IS NULL THEN 1 ELSE 0 END")
            ->orderByRaw("({$expression}) {$direction}")
            ->orderByDesc('publication.provider_published_at')
            ->orderBy('publication.id')
            ->paginate(self::POSTS_PAGE_SIZE, ['*'], 'posts_page', $page);
        $rows = collect($paginator->items());
        $media = $this->media($rows);
        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();

        return [
            'data' => $rows->values()->map(fn (object $row, int $index): array => $this->row($row, $offset + $index + 1, $workspace, $channels, $media))->all(),
            'sort' => $sort,
            'direction' => $direction,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @param  list<string>|null  $accountKeys */
    private function base(Workspace $workspace, DateRange $range, ?array $accountKeys): Builder
    {
        return $this->publications->query($workspace, $range->startsAt(), $range->endsAt(), $accountKeys)
            ->addSelect('publication.workspace_id')
            ->selectRaw(self::ENGAGEMENT.' as tantra_engagement')
            ->selectRaw(self::IMPRESSIONS.' as tantra_impressions')
            ->selectRaw('('.self::ENGAGEMENT.' * 1.0) / NULLIF('.self::EXPOSURE.', 0) as tantra_engagement_rate');
    }

    private function expression(string $sort): string
    {
        return match ($sort) {
            'engagement' => self::ENGAGEMENT,
            'impressions' => self::IMPRESSIONS,
            'reactions' => 'metric.reactions_count',
            'comments' => 'metric.comments_count',
            'shares' => 'metric.shares_count',
            'engagement_rate' => '('.self::ENGAGEMENT.' * 1.0) / NULLIF('.self::EXPOSURE.', 0)',
            default => 'publication.provider_published_at',
        };
    }

    /**
     * The first image or video of each post the Engine published, keyed by post id.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    private function media(Collection $rows): Collection
    {
        $ids = $rows->pluck('post_id')->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Post::query()
            ->whereKey($ids)
            ->get(['id', 'media'])
            ->mapWithKeys(fn (Post $post): array => [$post->id => $post->coverMedia()?->toArray()])
            ->filter();
    }

    /**
     * One post in the shape of upstream's Top 5 cards, with every sort's number. The
     * Engine post opens only from its own workspace; others open their publication.
     *
     * @param  Collection<string, array<string, mixed>>  $channels
     * @param  Collection<string, array<string, mixed>>  $media
     * @return array<string, mixed>
     */
    private function row(object $row, int $rank, Workspace $workspace, Collection $channels, Collection $media): array
    {
        $channel = $channels->get((string) $row->social_account_key);
        $rate = $row->tantra_engagement_rate === null ? null : round((float) $row->tantra_engagement_rate * 100, 2);

        return [
            'id' => $row->id,
            'rank' => $rank,
            'post_id' => $row->workspace_id === $workspace->id ? $row->post_id : null,
            'workspace_id' => $row->workspace_id,
            'person' => data_get($channel, 'person'),
            'social_account_key' => $row->social_account_key,
            'platform' => $row->platform,
            'name' => data_get($channel, 'display_label') ?? $row->account_display_name,
            'username' => $row->account_username,
            'avatar_url' => data_get($channel, 'avatar_url') ?? $row->account_avatar_url,
            'status' => data_get($channel, 'status'),
            'verified_badge' => data_get($channel, 'verified_badge'),
            'origin' => $row->origin,
            'content_type' => $row->content_type,
            'availability' => $row->availability,
            'published_at' => CarbonImmutable::parse($row->provider_published_at, 'UTC')->toIso8601String(),
            'permalink' => PublicationAvailability::from($row->availability)->visiblePermalink($row->permalink),
            'excerpt' => $row->excerpt,
            'preview_metadata' => $row->preview_metadata ? json_decode((string) $row->preview_metadata, true) : null,
            'media' => $row->post_id === null ? null : $media->get((string) $row->post_id),
            'engagement' => $this->integer($row->tantra_engagement),
            'impressions' => $this->integer($row->tantra_impressions),
            'reactions' => $this->integer($row->reactions_count),
            'comments' => $this->integer($row->comments_count),
            'shares' => $this->integer($row->shares_count),
            'engagement_rate' => $rate,
        ];
    }

    private function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
