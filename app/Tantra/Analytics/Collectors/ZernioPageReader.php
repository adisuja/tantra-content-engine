<?php

declare(strict_types=1);

namespace App\Tantra\Analytics\Collectors;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use App\Tantra\Zernio\ZernioClient;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * tantra: the Tantra page's numbers through Zernio for the upstream Insights
 * collectors (PRD 25.7): followers from the account list, the page's posts made
 * through Zernio, and each post's LinkedIn stats by URN. Our own tantra:collect-metrics
 * shares the "analytics need an add-on" pause (402) with these reads.
 */
class ZernioPageReader
{
    public const string ADD_ON_PAUSE = 'tantra:analytics:zernio-off';

    public function __construct(private readonly ZernioClient $zernio) {}

    /** @throws AnalyticsCollectionException */
    public function accountFor(SocialAccount $channel): string
    {
        if (! ZernioClient::configured()) {
            throw new AnalyticsCollectionException('permission', 'Zernio is not set up on the server, so the page numbers cannot be read.');
        }

        if (blank($channel->platform_user_id)) {
            throw new AnalyticsCollectionException('permission', 'This page channel has no Zernio account.');
        }

        return (string) $channel->platform_user_id;
    }

    /** The page's followers: the identity sync's count when a day old at most, else Zernio's account list. */
    public function followers(SocialAccount $channel, string $accountId): int
    {
        $syncedAt = data_get($channel->meta, 'linkedin_synced_at');
        $synced = data_get($channel->meta, 'linkedin_followers');

        if (is_numeric($synced) && is_string($syncedAt) && now()->subDay()->lessThanOrEqualTo($syncedAt)) {
            return (int) $synced;
        }

        $followers = Cache::remember("tantra:insights:zernio-followers:{$accountId}", now()->addHours(6), function () use ($accountId): ?int {
            $response = $this->call(fn (): Response => $this->zernio->account($accountId), 'Zernio account read');
            $account = collect((array) $response->json('accounts'))
                ->first(fn (mixed $a): bool => is_array($a) && (string) ($a['_id'] ?? $a['id'] ?? '') === $accountId);
            $value = is_array($account) ? ($account['followersCount'] ?? null) : null;

            return is_numeric($value) ? (int) $value : null;
        });

        if ($followers === null) {
            Cache::forget("tantra:insights:zernio-followers:{$accountId}");

            throw AnalyticsCollectionException::malformed('missing follower metric');
        }

        return $followers;
    }

    public function posts(int $page, int $limit): Response
    {
        return $this->call(fn (): Response => $this->zernio->publishedLinkedInPosts($page, $limit), 'Zernio post list read');
    }

    /** @return array<string, mixed> impressions, reach, likes, comments, shares, clicks */
    public function postAnalytics(string $accountId, string $urn): array
    {
        if (Cache::has(self::ADD_ON_PAUSE)) {
            throw new AnalyticsCollectionException('permission', 'Zernio analytics need its analytics add-on.');
        }

        $response = $this->call(fn (): Response => $this->zernio->linkedInPostAnalytics($accountId, $urn), 'Zernio post analytics read');

        return (array) $response->json('analytics');
    }

    /** The post's LinkedIn URN, from its link or, once, from Zernio's copy of the post. */
    public function urnOf(string $remoteId, ?string $permalink): ?string
    {
        foreach ([(string) $permalink, $remoteId] as $candidate) {
            $urns = UnipileLinkedInReader::urns($candidate);

            if ($urns !== []) {
                return $urns[0];
            }
        }

        $key = "tantra:insights:zernio-urn:{$remoteId}";
        $known = Cache::get($key);

        if (is_string($known)) {
            return $known;
        }

        $response = $this->call(fn (): Response => $this->zernio->getPost($remoteId), 'Zernio post read');
        $post = (array) ($response->json('post') ?? $response->json());

        foreach ((array) ($post['platforms'] ?? []) as $target) {
            $urns = UnipileLinkedInReader::urns((string) data_get($target, 'platformPostUrl').' '.data_get($target, 'platformPostId'));

            if ($urns !== []) {
                Cache::forever($key, $urns[0]);

                return $urns[0];
            }
        }

        return null;
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws AnalyticsCollectionException
     */
    private function call(Closure $request, string $operation): Response
    {
        try {
            $response = $request();
        } catch (Throwable $e) {
            throw UnipileLinkedInReader::unreachable('Zernio', $e);
        }

        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 402) {
            Cache::put(self::ADD_ON_PAUSE, true, now()->addDay());

            throw new AnalyticsCollectionException('permission', 'Zernio analytics need its analytics add-on.');
        }

        $status = $response->status();
        $category = match (true) {
            $status === 429 => 'rate_limited',
            $status === 401 => 'authentication',
            in_array($status, [403, 404, 410], true) => 'permission',
            $status >= 500 => 'transient',
            default => 'malformed',
        };

        throw new AnalyticsCollectionException($category, "{$operation} failed with HTTP {$status}", RetryAfter::from($response));
    }
}
