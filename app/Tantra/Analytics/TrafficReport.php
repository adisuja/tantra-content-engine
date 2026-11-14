<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Tantra\Links\CrmTracking;
use App\Tantra\Links\LinkRules;
use App\Tantra\Links\Piece;
use App\Tantra\Links\TrackedLink;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: the traffic and lead tracking module on /tantra/insights. Per team
 * member and across every connected platform:
 *
 * - Social, from the Engine's own analytics snapshots (upstream analytics_* tables).
 * - Traffic and funnel, from the Tantra CRM report API (POST /api/trk/reports/query):
 *   each piece's links carry a CRM asset, so `group_by: asset` gives clicks,
 *   registrations, bookings, sales and revenue per piece, summed per person;
 *   `group_by: channel` gives visits per platform; `group_by: day` the team series.
 *   What the CRM cannot split yet (visits and leads per person or piece) comes
 *   back as null with the reason, never as zero.
 */
final class TrafficReport
{
    public const BUCKETS = ['day', 'week', 'month'];

    public const SOCIAL = ['posts', 'impressions', 'likes', 'comments', 'shares', 'followers', 'followers_gained'];

    public const TRAFFIC = ['clicks', 'clicks_human', 'visits', 'conversions', 'registrations', 'bookings', 'leads', 'sales', 'revenue'];

    private const CRM_METRICS = ['clicks_raw', 'clicks_human', 'visitors', 'registrations', 'calls_booked', 'leads', 'deals_won', 'revenue_attributed'];

    /** Metrics a platform's API does not give, so the UI says so instead of showing 0. */
    private const NOT_GIVEN = [
        'linkedin' => ['impressions', 'shares', 'followers', 'followers_gained'],
        'facebook' => [],
        'x' => ['followers', 'followers_gained'],
    ];

    /**
     * @param  list<string>  $workspaceIds
     * @return array<string, mixed>
     */
    public static function build(array $workspaceIds, int $days, string $bucket, ?CarbonImmutable $today = null): array
    {
        $bucket = in_array($bucket, self::BUCKETS, true) ? $bucket : 'day';
        $to = ($today ?? CarbonImmutable::now('UTC'))->startOfDay();
        $from = $to->subDays($days - 1);

        $members = self::members($workspaceIds);
        $social = self::social($members, $from, $to, $bucket);
        $crm = self::crm($members, $from, $to, $bucket);

        $rows = $members->map(function (array $m) use ($social, $crm): array {
            $s = $social['members'][$m['key']] ?? self::emptySocial();
            $t = $crm['members'][$m['key']] ?? self::emptyTraffic($crm['live']);

            return [...$m, 'social' => $s['totals'], 'social_notes' => $s['notes'], 'traffic' => $t, 'profile_clicks' => $crm['profile_clicks'][$m['key']] ?? null];
        })->values();

        $totals = [
            'social' => self::sumRows($rows->pluck('social'), self::SOCIAL),
            'traffic' => self::sumRows($rows->pluck('traffic'), self::TRAFFIC),
        ];
        // Visits and leads are known for the team (the campaign), not per person.
        $totals['traffic']['visits'] = $crm['team']['visits'] ?? null;
        $totals['traffic']['leads'] = $crm['team']['leads'] ?? null;

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days, 'bucket' => $bucket,
                'weeks' => round($days / 7, 2), 'months' => round($days / 30.4375, 2)],
            'crm' => ['status' => $crm['status'], 'message' => $crm['message'], 'currency' => $crm['currency'], 'generated_at' => $crm['generated_at'],
                'not_split' => $crm['not_split']],
            'members' => $rows->all(),
            'totals' => $totals,
            'series' => self::mergeSeries($social['series'], $crm['series']),
            'platforms' => array_values(self::mergeKeyed($social['platforms'], $crm['platforms'])),
            'pieces' => $crm['pieces'],
            'buckets' => self::BUCKETS,
        ];
    }

    /** @return Collection<int, array{key: string, name: string, workspace_id: string, platforms: list<string>}> */
    private static function members(array $workspaceIds): Collection
    {
        $settings = WorkspaceSettings::query()->whereIn('workspace_id', $workspaceIds)->pluck('brand_profile_key', 'workspace_id');
        $channels = SocialAccount::query()->whereIn('workspace_id', $workspaceIds)->get(['workspace_id', 'platform'])->groupBy('workspace_id');
        $linked = TrackedLink::query()->whereIn('workspace_id', $workspaceIds)->distinct()->pluck('workspace_id')->all();

        return Workspace::query()->whereIn('id', $workspaceIds)->orderBy('created_at')->get(['id', 'name'])
            ->filter(fn (Workspace $w): bool => $channels->has($w->id) || in_array($w->id, $linked, true))
            ->map(fn (Workspace $w): array => [
                'key' => Piece::memberKey($w->id, $settings[$w->id] ?? null),
                'name' => (string) $w->name,
                'workspace_id' => (string) $w->id,
                'platforms' => $channels->get($w->id, collect())->map(fn (SocialAccount $a): string => LinkRules::platform($a->platform->value))->unique()->values()->all(),
            ])->values();
    }

    /** @return array{members: array<string, mixed>, series: array<string, array<string, mixed>>, platforms: array<string, array<string, mixed>>} */
    private static function social(Collection $members, CarbonImmutable $from, CarbonImmutable $to, string $bucket): array
    {
        $byWorkspace = $members->keyBy('workspace_id');
        $ids = $members->pluck('workspace_id')->all();

        // Each post published in the range, with its latest numbers up to the end of the range.
        $latest = DB::table('analytics_publication_daily_snapshots')
            ->selectRaw('publication_id, MAX(date) AS d')->where('date', '<=', $to->toDateString())->groupBy('publication_id');
        $posts = DB::table('analytics_publications as p')
            ->leftJoinSub($latest, 'l', 'l.publication_id', '=', 'p.id')
            ->leftJoin('analytics_publication_daily_snapshots as s', fn ($j) => $j->on('s.publication_id', '=', 'p.id')->on('s.date', '=', 'l.d'))
            ->whereIn('p.workspace_id', $ids)
            ->whereNotNull('p.provider_published_at')
            ->whereBetween('p.provider_published_at', [$from->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->get(['p.workspace_id', 'p.platform', 'p.provider_published_at', 's.impressions_count', 's.reactions_count', 's.comments_count', 's.shares_count']);

        $out = ['members' => [], 'series' => [], 'platforms' => []];
        foreach ($posts as $row) {
            $m = $byWorkspace->get($row->workspace_id);
            if ($m === null) {
                continue;
            }
            $platform = LinkRules::platform((string) $row->platform);
            $values = ['posts' => 1, 'impressions' => self::n($row->impressions_count), 'likes' => self::n($row->reactions_count),
                'comments' => self::n($row->comments_count), 'shares' => self::n($row->shares_count)];
            $out['members'][$m['key']]['totals'] = self::add($out['members'][$m['key']]['totals'] ?? self::emptySocial()['totals'], $values);
            $b = self::bucketOf(CarbonImmutable::parse((string) $row->provider_published_at), $bucket);
            $out['series'][$b.'|'.$m['key']] = self::add($out['series'][$b.'|'.$m['key']] ?? ['bucket' => $b, 'member' => $m['key']], $values);
            $pk = $m['key'].'|'.$platform;
            $out['platforms'][$pk] = self::add($out['platforms'][$pk] ?? ['member' => $m['key'], 'platform' => $platform], $values);
        }

        // Followers: the latest count per channel at the end of the range, and the gain inside it.
        $snap = DB::table('analytics_account_daily_snapshots')->whereIn('workspace_id', $ids)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNotNull('followers_count')
            ->orderBy('date')->get(['workspace_id', 'social_account_id', 'platform', 'date', 'followers_count'])->groupBy('social_account_id');
        foreach ($snap as $rowsForChannel) {
            $first = $rowsForChannel->first();
            $last = $rowsForChannel->last();
            $m = $byWorkspace->get($first->workspace_id);
            if ($m === null) {
                continue;
            }
            $platform = LinkRules::platform((string) $first->platform);
            $values = ['followers' => (int) $last->followers_count, 'followers_gained' => $rowsForChannel->count() > 1 ? (int) $last->followers_count - (int) $first->followers_count : null];
            $out['members'][$m['key']]['totals'] = self::add($out['members'][$m['key']]['totals'] ?? self::emptySocial()['totals'], $values);
            $pk = $m['key'].'|'.$platform;
            $out['platforms'][$pk] = self::add($out['platforms'][$pk] ?? ['member' => $m['key'], 'platform' => $platform], $values);
        }

        // What each person's platforms cannot give: a metric missing on every one of their platforms.
        foreach ($members as $m) {
            $out['members'][$m['key']] ??= self::emptySocial();
            $notes = [];
            foreach (['impressions', 'shares', 'followers'] as $metric) {
                $given = collect($out['platforms'])->where('member', $m['key'])->contains(fn (array $p): bool => ($p[$metric] ?? null) !== null);
                if (! $given && ($m['platforms'] !== [] || ($out['members'][$m['key']]['totals']['posts'] ?? 0) > 0)) {
                    $notes[$metric] = self::notGivenReason($m['platforms'], $metric);
                }
            }
            $out['members'][$m['key']]['notes'] = $notes;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function crm(Collection $members, CarbonImmutable $from, CarbonImmutable $to, string $bucket): array
    {
        $base = ['live' => false, 'status' => 'not_configured', 'message' => null, 'currency' => null, 'generated_at' => null,
            'members' => [], 'team' => [], 'series' => [], 'platforms' => [], 'pieces' => [], 'profile_clicks' => [], 'not_split' => self::notSplit()];
        $links = TrackedLink::query()->whereIn('workspace_id', $members->pluck('workspace_id'))->get();
        $base['pieces'] = self::pieces($links, []);

        if (! CrmTracking::configured()) {
            $base['message'] = 'TANTRA_CRM_API_KEY is not set on the server, so clicks, visits and conversions cannot be read yet.';

            return $base;
        }

        try {
            $data = Cache::remember('tantra:traffic:'.$from->toDateString().':'.$to->toDateString().':'.$bucket.':'.md5($links->pluck('crm_asset_id')->sort()->implode(',')), 600,
                function () use ($from, $to): array {
                    $crm = app(CrmTracking::class);
                    $range = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
                    $filters = ['campaign_ids' => [$crm->campaignId()]];

                    return [
                        'asset' => $crm->query(['metrics' => self::CRM_METRICS, 'group_by' => ['asset'], 'filters' => $filters, 'range' => $range]),
                        'channel' => $crm->query(['metrics' => self::CRM_METRICS, 'group_by' => ['channel'], 'filters' => $filters, 'range' => $range]),
                        'day' => $crm->query(['metrics' => self::CRM_METRICS, 'group_by' => ['day'], 'filters' => $filters, 'range' => $range]),
                        'links' => $crm->linkClicks(),
                    ];
                });
        } catch (Throwable $e) {
            return [...$base, 'status' => 'error', 'message' => 'The Tantra CRM report did not answer: '.$e->getMessage()];
        }

        $byAsset = collect((array) ($data['asset']['rows'] ?? []))->keyBy('asset');
        $assetMember = $links->whereNotNull('crm_asset_id')->groupBy('crm_asset_id')->map(fn (Collection $g): string => (string) $g->first()->member_key);

        $membersOut = [];
        foreach ($members as $m) {
            $membersOut[$m['key']] = self::emptyTraffic(true);
        }
        foreach ($byAsset as $assetId => $row) {
            $key = $assetMember[$assetId] ?? null;
            if ($key !== null && isset($membersOut[$key])) {
                $membersOut[$key] = self::add($membersOut[$key], self::traffic($row), keepNull: ['visits', 'leads']);
            }
        }

        $totals = (array) ($data['day']['totals'] ?? []);
        $series = [];
        foreach ((array) ($data['day']['rows'] ?? []) as $row) {
            $b = self::bucketOf(CarbonImmutable::parse((string) $row['day']), $bucket);
            $series[$b.'|_team'] = self::add($series[$b.'|_team'] ?? ['bucket' => $b, 'member' => '_team'], self::traffic($row));
        }

        $platforms = [];
        foreach ((array) ($data['channel']['rows'] ?? []) as $row) {
            $channel = (string) ($row['channel'] ?? 'none');
            if (in_array($channel, ['none', 'direct', 'calendar', 'email'], true) && ! $links->contains('platform', $channel)) {
                continue;
            }
            $platforms['_team|'.$channel] = ['member' => '_team', 'platform' => $channel, ...self::traffic($row)];
        }

        // Profile and Featured links (<key>-fw and the rest) have no piece: their clicks, all time, per person.
        $ours = $links->pluck('crm_link_id')->filter()->flip();
        $keys = $members->pluck('key')->sortByDesc(fn (string $k): int => strlen($k))->values();
        $profileClicks = [];
        foreach ((array) ($data['links'] ?? []) as $id => $link) {
            if ($ours->has($id)) {
                continue;
            }
            $key = $keys->first(fn (string $k): bool => str_starts_with((string) $link['code'], $k.'-'));
            if ($key !== null) {
                $profileClicks[$key] = ['links' => ($profileClicks[$key]['links'] ?? 0) + 1,
                    'clicks' => ($profileClicks[$key]['clicks'] ?? 0) + $link['raw'], 'clicks_human' => ($profileClicks[$key]['clicks_human'] ?? 0) + $link['human']];
            }
        }

        return [...$base, 'live' => true, 'status' => 'live', 'message' => null,
            'currency' => $data['asset']['currency'] ?? null, 'generated_at' => $data['asset']['generated_at'] ?? null,
            'members' => $membersOut,
            'team' => ['visits' => self::n($totals['visitors'] ?? null), 'leads' => null],
            'series' => $series, 'platforms' => $platforms, 'profile_clicks' => $profileClicks,
            'pieces' => self::pieces($links, $byAsset->all())];
    }

    /** @return list<array<string, mixed>> */
    private static function pieces(Collection $links, array $byAsset): array
    {
        $items = ReviewItem::query()->whereIn('id', $links->pluck('piece_ref')->filter(fn (string $r): bool => str_starts_with($r, 'ri:'))->map(fn (string $r): string => substr($r, 3))->filter(fn (string $id): bool => Str::isUuid($id))->unique())->get()->keyBy('id');

        return $links->groupBy(fn (TrackedLink $l): string => $l->member_key.'|'.$l->piece_ref.'|'.$l->platform)
            ->map(function (Collection $g) use ($byAsset, $items): array {
                /** @var TrackedLink $first */
                $first = $g->first();
                $row = $byAsset[$first->crm_asset_id] ?? null;
                $item = str_starts_with($first->piece_ref, 'ri:') ? $items->get(substr($first->piece_ref, 3)) : null;

                return [
                    'member' => $first->member_key,
                    'piece' => (string) $first->piece_name,
                    'platform' => $first->platform,
                    'review_url' => $item?->shareUrl(),
                    'links' => $g->map(fn (TrackedLink $l): array => ['placement' => $l->placement, 'url' => $l->url(), 'code' => $l->code])->values()->all(),
                    ...($row !== null ? self::traffic($row) : self::emptyTraffic($byAsset !== [])),
                ];
            })->sortByDesc(fn (array $p): int => (int) ($p['clicks'] ?? 0))->values()->all();
    }

    /** One CRM row as our traffic metrics. Visits and leads are not split by piece in the CRM yet. */
    private static function traffic(array $row): array
    {
        $reg = self::n($row['registrations'] ?? null);
        $book = self::n($row['calls_booked'] ?? null);

        return [
            'clicks' => self::n($row['clicks_raw'] ?? null),
            'clicks_human' => self::n($row['clicks_human'] ?? null),
            'visits' => self::n($row['visitors'] ?? null),
            'conversions' => $reg === null && $book === null ? null : (int) $reg + (int) $book,
            'registrations' => $reg,
            'bookings' => $book,
            'leads' => self::n($row['leads'] ?? null),
            'sales' => self::n($row['deals_won'] ?? null),
            'revenue' => isset($row['revenue_attributed']) ? round(((int) $row['revenue_attributed']) / 100, 2) : null,
        ];
    }

    /** @return array<string, string> */
    private static function notSplit(): array
    {
        return [
            'visits' => 'The CRM counts visits per campaign and platform. It does not split them per person or piece yet, so visits show for the team and per platform only.',
            'leads' => 'The CRM does not tie a new lead to the link that brought it yet. Registrations and bookings are the conversions it does tie to each link.',
        ];
    }

    private static function notGivenReason(array $platforms, string $metric): string
    {
        $linkedInOnly = $platforms !== [] && collect($platforms)->every(fn (string $p): bool => $p === 'linkedin');
        if ($linkedInOnly) {
            return match ($metric) {
                'followers' => 'LinkedIn has not given a follower count for this profile yet.',
                default => 'LinkedIn personal profiles give only likes and comments per post through the API.',
            };
        }

        return 'Not given by the connected platforms\' APIs for this period.';
    }

    /** @return array{totals: array<string, int|null>, notes: array<string, string>} */
    private static function emptySocial(): array
    {
        return ['totals' => array_fill_keys(self::SOCIAL, null), 'notes' => []];
    }

    /** @return array<string, int|float|null> */
    private static function emptyTraffic(bool $live): array
    {
        $zero = $live ? 0 : null;

        return ['clicks' => $zero, 'clicks_human' => $zero, 'visits' => null, 'conversions' => $zero, 'registrations' => $zero,
            'bookings' => $zero, 'leads' => null, 'sales' => $zero, 'revenue' => $live ? 0.0 : null];
    }

    /** Adds numbers; null plus null stays null (not given), null plus a number is the number. */
    private static function add(array $into, array $values, array $keepNull = []): array
    {
        foreach ($values as $k => $v) {
            if (in_array($k, $keepNull, true)) {
                continue;
            }
            if (is_int($v) || is_float($v)) {
                $into[$k] = ($into[$k] ?? 0) + $v;
            } elseif (! array_key_exists($k, $into)) {
                $into[$k] = $v;
            }
        }

        return $into;
    }

    private static function sumRows(Collection $rows, array $keys): array
    {
        $out = array_fill_keys($keys, null);
        foreach ($rows as $row) {
            $out = self::add($out, array_intersect_key((array) $row, $out));
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function mergeSeries(array $social, array $crm): array
    {
        return collect($social)->merge($crm)->values()->sortBy('bucket')->values()->all();
    }

    private static function bucketOf(CarbonImmutable $d, string $bucket): string
    {
        return match ($bucket) {
            'week' => $d->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'month' => $d->format('Y-m'),
            default => $d->toDateString(),
        };
    }

    /**
     * Two keyed sets of rows, the second's fields added to the first's row with the same key.
     *
     * @param  array<string, array<string, mixed>>  $a
     * @param  array<string, array<string, mixed>>  $b
     * @return array<string, array<string, mixed>>
     */
    private static function mergeKeyed(array $a, array $b): array
    {
        foreach ($b as $k => $row) {
            $a[$k] = isset($a[$k]) ? [...$a[$k], ...$row] : $row;
        }

        return $a;
    }

    private static function n(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
