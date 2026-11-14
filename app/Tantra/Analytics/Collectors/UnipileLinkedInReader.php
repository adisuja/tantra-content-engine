<?php

declare(strict_types=1);

namespace App\Tantra\Analytics\Collectors;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Exceptions\PlatformUnavailableException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * tantra: reads a LinkedIn profile's numbers through Unipile for the upstream
 * Insights collectors (PRD 25.7). A Unipile LinkedIn channel is read through its
 * own account (platform_user_id); an OAuth LinkedIn channel, whose grant has no
 * analytics scope, through the workspace review owner's matched Unipile account.
 *
 * Unipile limits users/me and LinkedIn watches volume, so every read is cached:
 * the profile for 6 hours (or the identity sync's follower count when it is a
 * day old at most) and each page of the post list for an hour, shared by the
 * discovery and every post's metrics read.
 */
class UnipileLinkedInReader
{
    public const int PAGE_SIZE = 50;

    public const int POSTS_TTL_MINUTES = 60;

    public const int PROFILE_TTL_HOURS = 6;

    /** How far a metrics read pages back through the list to find one post. */
    public const int MAX_PAGES = 6;

    /** @var array<string, array<string, string>> per channel: every key one of our posts is known by => its platform_post_id */
    private array $keys = [];

    /** @var array<string, list<array{urn: string, remote: string, published_at: ?CarbonImmutable}>> */
    private array $urnPosts = [];

    public function __construct(private readonly UnipileClient $unipile) {}

    /**
     * The Unipile account a channel is read through.
     *
     * @throws AnalyticsCollectionException
     */
    public function accountFor(SocialAccount $channel): string
    {
        if (! UnipileClient::configured()) {
            throw new AnalyticsCollectionException('permission', 'Unipile is not set up on the server, so LinkedIn numbers cannot be read.');
        }

        if ($channel->platform === Platform::UnipileLinkedIn) {
            if (blank($channel->platform_user_id)) {
                throw new AnalyticsCollectionException('permission', 'This LinkedIn channel has no Unipile account.');
            }

            return (string) $channel->platform_user_id;
        }

        $ownerId = WorkspaceSettings::query()->find($channel->workspace_id)?->review_owner_user_id;
        $profile = $ownerId ? MemberProfile::query()->find($ownerId) : null;

        // Reading their own posts' numbers posts nothing, so a match by name is enough here; posting in their
        // name still needs the confirmed match and their consent (Team page).
        if (blank($profile?->unipile_account_id)) {
            throw new AnalyticsCollectionException('permission', 'no Unipile account matched for this LinkedIn profile');
        }

        return (string) $profile->unipile_account_id;
    }

    /**
     * The profile's followers: the identity sync's count when it is fresh,
     * else users/me, cached.
     *
     * @throws AnalyticsCollectionException
     */
    public function followers(string $accountId): int
    {
        $synced = MemberProfile::query()
            ->where('unipile_account_id', $accountId)
            ->whereNotNull('linkedin_followers')
            ->where('linkedin_synced_at', '>=', now()->subDay())
            ->value('linkedin_followers');

        if (is_numeric($synced)) {
            return (int) $synced;
        }

        $key = "tantra:insights:unipile-me:{$accountId}";
        $me = Cache::get($key);

        if (! is_array($me)) {
            try {
                $answer = $this->unipile->profile($accountId);
            } catch (Throwable $e) {
                throw self::unreachable('Unipile', $e);
            }

            if ($answer['data'] === null) {
                throw self::failed($answer['status'], 'LinkedIn profile read', null);
            }

            $me = $answer['data'];
            Cache::put($key, $me, now()->addHours(self::PROFILE_TTL_HOURS));
        }

        $followers = $me['followers_count'] ?? $me['follower_count'] ?? null;

        if (! is_numeric($followers)) {
            throw AnalyticsCollectionException::malformed('missing follower metric');
        }

        return (int) $followers;
    }

    /**
     * One page of the account's own posts, newest first, cached for an hour.
     *
     * @return array{items: list<array<string, mixed>>, next: ?string}
     *
     * @throws AnalyticsCollectionException
     */
    public function page(string $accountId, ?string $cursor): array
    {
        $key = "tantra:insights:unipile-posts:{$accountId}:".($cursor === null ? 'first' : hash('sha256', $cursor));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->unipile->ownPosts($accountId, self::PAGE_SIZE, $cursor);
        } catch (Throwable $e) {
            throw self::unreachable('Unipile', $e);
        }

        if (! $response->successful()) {
            if ($cursor !== null && in_array($response->status(), [400, 422], true)) {
                throw new AnalyticsCollectionException('invalid_cursor', 'LinkedIn post list cursor expired');
            }

            throw self::failed($response->status(), 'LinkedIn post list read', $response);
        }

        $next = $response->json('cursor') ?? $response->json('next_cursor') ?? $response->json('paging.cursor');
        $page = [
            'items' => array_values(array_filter((array) ($response->json('items') ?? $response->json('data') ?? []), 'is_array')),
            'next' => is_string($next) && $next !== '' ? $next : null,
        ];
        Cache::put($key, $page, now()->addMinutes(self::POSTS_TTL_MINUTES));

        return $page;
    }

    /**
     * The id a listed post is stored under: the platform_post_id of the Engine
     * post it is (so our own posts link up), else Unipile's own id.
     *
     * @param  array<string, mixed>  $item
     */
    public function providerId(SocialAccount $channel, string $accountId, array $item): ?string
    {
        $candidates = self::keysOf($item);

        if ($candidates === []) {
            return null;
        }

        $keys = $this->ownKeys($channel);

        foreach ($candidates as $candidate) {
            if (isset($keys[$candidate])) {
                return $keys[$candidate];
            }
        }

        $matched = $this->resolveNear($channel, $accountId, $item);

        return $matched ?? $candidates[0];
    }

    /**
     * A post from the cached list by the id it is stored under, paging back until
     * the list is older than the post.
     *
     * @return array<string, mixed>|null
     *
     * @throws AnalyticsCollectionException
     */
    public function find(SocialAccount $channel, string $accountId, string $remoteId, ?CarbonImmutable $publishedAt): ?array
    {
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $list = $this->page($accountId, $cursor);
            $older = false;

            foreach ($list['items'] as $item) {
                if ($this->providerId($channel, $accountId, $item) === $remoteId) {
                    return $item;
                }

                $created = self::publishedAt($item);
                $older = $older || ($publishedAt !== null && $created !== null && $created->lessThan($publishedAt->subDay()));
            }

            if ($older || $list['next'] === null) {
                return null;
            }

            $cursor = $list['next'];
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    public static function publishedAt(array $item): ?CarbonImmutable
    {
        foreach (['created_at', 'parsed_datetime', 'published_at'] as $field) {
            $value = $item[$field] ?? null;

            if (is_string($value) && $value !== '') {
                try {
                    return CarbonImmutable::parse($value)->utc();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * The post's numbers, null where Unipile gives none (never 0 for missing).
     *
     * @param  array<string, mixed>  $item
     * @return array{impressions: ?int, reactions: ?int, comments: ?int, reposts: ?int}
     */
    public static function counters(array $item): array
    {
        $reactions = $item['reactions_counter'] ?? $item['reaction_counter'] ?? null;

        if (is_array($reactions)) {
            $reactions = array_sum(array_map(
                fn (mixed $entry): int => is_array($entry) ? (int) ($entry['count'] ?? 0) : (is_numeric($entry) ? (int) $entry : 0),
                $reactions,
            ));
        }

        return [
            'impressions' => self::int(data_get($item, 'analytics.impressions_counter') ?? $item['impressions_counter'] ?? null),
            'reactions' => self::int($reactions),
            'comments' => self::int($item['comments_counter'] ?? $item['comment_counter'] ?? null),
            'reposts' => self::int($item['reposts_counter'] ?? $item['repost_counter'] ?? null),
        ];
    }

    /** `urn:li:share:1`, `share:1`, an `activity-1` link: each LinkedIn post id found, as a URN. @return list<string> */
    public static function urns(string $value): array
    {
        preg_match_all('/(?:urn:li:)?(share|ugcPost|activity)[:-](\d{10,})/', $value, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(fn (array $m): string => "urn:li:{$m[1]}:{$m[2]}", $matches)));
    }

    /**
     * Every id a listed post is known by: Unipile's id, then its URNs.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private static function keysOf(array $item): array
    {
        $id = is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
        $keys = $id !== '' ? [$id] : [];

        if ($id !== '' && ctype_digit($id)) {
            $keys[] = "urn:li:activity:{$id}";
        }

        foreach (['social_id', 'share_url', 'url'] as $field) {
            if (is_string($item[$field] ?? null)) {
                array_push($keys, ...self::urns($item[$field]));
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return array<string, string> */
    private function ownKeys(SocialAccount $channel): array
    {
        if (isset($this->keys[$channel->id])) {
            return $this->keys[$channel->id];
        }

        $keys = [];
        $urnPosts = [];

        Post::query()
            ->where('social_account_id', $channel->id)
            ->whereNotNull('platform_post_id')
            ->where('published_at', '>=', now()->subDays(400))
            ->get(['id', 'platform', 'platform_post_id', 'platform_url', 'published_at', 'meta'])
            ->each(function (Post $post) use (&$keys, &$urnPosts): void {
                $remote = (string) $post->platform_post_id;
                $keys[$remote] ??= $remote;

                if (filled($unipileId = data_get($post->meta, 'unipile_post_id'))) {
                    $keys[(string) $unipileId] ??= $remote;
                }

                if (($urn = Boost::nativeRef($post)) !== null) {
                    $keys[$urn] ??= $remote;
                    $urnPosts[] = ['urn' => $urn, 'remote' => $remote, 'published_at' => $post->published_at?->toImmutable()];
                }
            });

        $this->urnPosts[$channel->id] = $urnPosts;

        return $this->keys[$channel->id] = $keys;
    }

    /**
     * A share URN (what LinkedIn's own API hands the OAuth publisher) is not the
     * activity id Unipile lists, so our posts published near this one are asked
     * Unipile for their own id once and remembered.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveNear(SocialAccount $channel, string $accountId, array $item): ?string
    {
        $created = self::publishedAt($item);
        $id = is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';

        if ($created === null || $id === '') {
            return null;
        }

        foreach ($this->urnPosts[$channel->id] ?? [] as $index => $post) {
            if ($post['published_at'] === null || abs($post['published_at']->diffInHours($created)) > 48) {
                continue;
            }

            $unipileId = $this->unipileIdOf($accountId, $post['urn']);
            unset($this->urnPosts[$channel->id][$index]);

            if ($unipileId !== null) {
                $this->keys[$channel->id][$unipileId] = $post['remote'];
            }

            if ($unipileId === $id) {
                return $post['remote'];
            }
        }

        return null;
    }

    private function unipileIdOf(string $accountId, string $urn): ?string
    {
        $key = "tantra:insights:unipile-post-id:{$accountId}:{$urn}";
        $known = Cache::get($key);

        if (is_string($known)) {
            return $known === '' ? null : $known;
        }

        try {
            $id = $this->unipile->postId($accountId, $urn);
        } catch (Throwable) {
            return null;
        }

        $id === null
            ? Cache::put($key, '', now()->addDay())
            : Cache::forever($key, $id);

        return $id;
    }

    /**
     * A failed read as the upstream categories. A missing account (404) is a
     * permission problem, never "the post is gone".
     */
    private static function failed(int $status, string $operation, ?Response $response): AnalyticsCollectionException
    {
        $category = match (true) {
            $status === 429 => 'rate_limited',
            $status === 401 => 'authentication',
            in_array($status, [403, 404, 410], true) => 'permission',
            $status >= 500 => 'transient',
            default => 'malformed',
        };

        return new AnalyticsCollectionException($category, "{$operation} failed with HTTP {$status}", $response ? RetryAfter::from($response) : null);
    }

    /**
     * A read that never got an answer, for any reason, is retried later like a
     * network blip: an analytics read must never fail the job that asked for it.
     */
    public static function unreachable(string $service, Throwable $error): AnalyticsCollectionException
    {
        if (! $error instanceof PlatformUnavailableException) {
            Log::warning("tantra insights: {$service} read failed", ['error' => $error->getMessage()]);
        }

        return new AnalyticsCollectionException('transient', "{$service} is unreachable.");
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
