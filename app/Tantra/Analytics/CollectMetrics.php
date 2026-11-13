<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Analytics\Collectors\UnipileLinkedInReader;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * tantra: the analytics pass (PRD 10.6), at 06:00 and 18:00 UTC. A post is read
 * at both runs for its first 14 days, then at the morning run until day 30.
 *
 * - Tantra page posts: Zernio's LinkedIn post stats by URN (impressions, reach, clicks).
 * - Personal posts: Unipile, through a Unipile channel's own account or, for OAuth
 * LinkedIn, the review owner's matched account; impressions when Unipile gives them.
 * - The page itself: follower gain and page views from Zernio, one row a day.
 */
final class CollectMetrics
{
    public const DAYS = 30;

    public const TWICE_A_DAY_FOR = 14;

    /** @return array{posts: int, accounts: int, skipped: int} */
    public static function run(ZernioClient $zernio, UnipileClient $unipile): array
    {
        $morning = now()->utc()->hour < 12;
        $counts = ['posts' => 0, 'accounts' => 0, 'skipped' => 0];

        $posts = Post::query()
            ->where('status', PostStatus::Published)
            ->whereIn('platform', [Platform::LinkedIn, Platform::UnipileLinkedIn, Platform::ZernioLinkedInPage])
            ->where('published_at', '>=', now()->subDays(self::DAYS))
            ->with('socialAccount.workspace.account')
            ->get();

        foreach ($posts as $post) {
            $old = $post->published_at->lt(now()->subDays(self::TWICE_A_DAY_FOR));
            $fresh = MetricSnapshot::query()->where('post_id', $post->id)->where('captured_at', '>', now()->subHours(6))->exists();
            if (($old && ! $morning) || $fresh) {
                continue;
            }

            $row = $post->platform === Platform::ZernioLinkedInPage ? self::fromZernio($zernio, $post) : self::fromUnipile($unipile, $post);
            if ($row === null) {
                $counts['skipped']++;

                continue;
            }

            MetricSnapshot::query()->create([
                ...$row,
                'post_id' => $post->id,
                'review_item_id' => $post->post_group_id ? ReviewItem::query()->where('post_group_id', $post->post_group_id)->value('id') : null,
                'captured_at' => now(),
                'engagement_rate' => self::rate($row),
            ]);
            $counts['posts']++;
        }

        if ($morning) {
            $counts['accounts'] = self::pageDays($zernio);
        }

        return $counts;
    }

    /**
     * Ours, for every source alike: (reactions + comments + shares) / impressions.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rate(array $row): ?float
    {
        $impressions = (int) ($row['impressions'] ?? 0);
        if ($impressions <= 0) {
            return null;
        }

        return round(((int) ($row['reactions'] ?? 0) + (int) ($row['comments'] ?? 0) + (int) ($row['shares'] ?? 0)) / $impressions, 4);
    }

    /** @return array<string, mixed>|null */
    private static function fromZernio(ZernioClient $zernio, Post $post): ?array
    {
        $accountId = (string) $post->socialAccount?->platform_user_id;
        $urn = Boost::nativeRef($post);
        if (! ZernioClient::configured() || $accountId === '' || $urn === null || Cache::has('tantra:analytics:zernio-off')) {
            return null;
        }

        $response = $zernio->linkedInPostAnalytics($accountId, $urn);
        if ($response->status() === 402) {
            // Analytics need a paid add-on on this Zernio plan: stop asking for a day.
            Cache::put('tantra:analytics:zernio-off', true, now()->addDay());
            Log::warning('tantra analytics: Zernio says analytics need an add-on (402).');

            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $a = (array) $response->json('analytics');

        return [
            'source' => 'zernio',
            'impressions' => self::int($a['impressions'] ?? null),
            'unique_impressions' => self::int($a['reach'] ?? null),
            'reactions' => self::int($a['likes'] ?? null),
            'comments' => self::int($a['comments'] ?? null),
            'shares' => self::int($a['shares'] ?? null),
            'clicks' => self::int($a['clicks'] ?? null),
            'saves' => null,
            'raw' => $response->json(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function fromUnipile(UnipileClient $unipile, Post $post): ?array
    {
        $channel = $post->socialAccount;
        $workspace = $channel?->workspace;
        // A Unipile channel's post id is Unipile's own; an OAuth post's is its LinkedIn URN.
        $native = $post->platform === Platform::UnipileLinkedIn && filled($post->platform_post_id)
            ? (string) $post->platform_post_id
            : Boost::nativeRef($post);
        if (! UnipileClient::configured() || $workspace === null || $native === null) {
            return null;
        }

        $accountId = self::unipileAccountFor($channel);
        if ($accountId === null) {
            return null;
        }

        $data = $unipile->postDetails($accountId, $native);
        if ($data === null || ! isset($data['id'])) {
            return null;
        }

        return [
            'source' => 'unipile',
            // The per post read rarely has impressions (Spike S2); the list read does (Insights).
            'impressions' => self::int(data_get($data, 'analytics.impressions_counter')),
            'unique_impressions' => null,
            'reactions' => UnipileLinkedInReader::counters($data)['reactions'],
            'comments' => self::int($data['comments_counter'] ?? null),
            'shares' => self::int($data['reposts_counter'] ?? null),
            'clicks' => null,
            'saves' => null,
            'raw' => array_intersect_key($data, array_flip(['id', 'reactions_counter', 'comments_counter', 'reposts_counter', 'analytics', 'date', 'parsed_datetime'])),
        ];
    }

    /**
     * The Unipile account a personal post is read through: a Unipile channel's
     * own account, else (OAuth LinkedIn) the workspace review owner's confirmed one.
     */
    private static function unipileAccountFor(SocialAccount $channel): ?string
    {
        if ($channel->platform === Platform::UnipileLinkedIn) {
            return filled($channel->platform_user_id) ? (string) $channel->platform_user_id : null;
        }

        $workspace = $channel->workspace;
        $authorId = ReviewGate::reviewOwnerIdFor($workspace->id, $workspace->account?->owner_id);
        $author = $authorId ? MemberProfile::query()->find($authorId) : null;
        if ($author?->unipile_account_id === null || $author->unipile_confirmed_at === null) {
            return null;
        }

        return (string) $author->unipile_account_id;
    }

    /** One row a day per Tantra page, for two days ago (LinkedIn's follower numbers lag). */
    private static function pageDays(ZernioClient $zernio): int
    {
        if (! ZernioClient::configured() || Cache::has('tantra:analytics:zernio-off')) {
            return 0;
        }

        $day = now()->utc()->subDays(2)->toDateString();
        $saved = 0;

        $pages = SocialAccount::query()->where('platform', Platform::ZernioLinkedInPage)
            ->whereIn('workspace_id', WorkspaceSettings::query()->select('workspace_id'))->get();

        foreach ($pages as $page) {
            if (AccountSnapshot::query()->where('social_account_id', $page->id)->whereDate('date', $day)->exists()) {
                continue;
            }

            $response = $zernio->linkedInOrgTotals((string) $page->platform_user_id, $day, $day, ['followers_gained', 'organic_followers_gained', 'page_views_total']);
            if (! $response->successful()) {
                continue;
            }

            $m = (array) $response->json('metrics');
            AccountSnapshot::query()->create([
                'social_account_id' => $page->id,
                'date' => $day,
                'followers' => null, // Zernio gives gains, not the total
                'follower_gain' => data_get($m, 'followers_gained.total') ?? data_get($m, 'organic_followers_gained.total'),
                'page_views' => self::int(data_get($m, 'page_views_total.total')),
                'raw' => $response->json(),
            ]);
            $saved++;
        }

        return $saved;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
