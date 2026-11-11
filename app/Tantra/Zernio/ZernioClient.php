<?php

declare(strict_types=1);

namespace App\Tantra\Zernio;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * tantra: the few Zernio API calls the Engine makes (PRD 10.4). The key comes
 * from config('tantra.zernio.key') and never reaches the browser or the database.
 */
class ZernioClient
{
    public static function configured(): bool
    {
        return filled(config('tantra.zernio.key'));
    }

    /** @return list<array<string, mixed>> */
    public function accounts(): array
    {
        return array_values((array) ($this->send('get', '/accounts')->json('accounts') ?? []));
    }

    /**
     * One account by its id: GET /accounts with `search` (a 24-character hex
     * value matches only that account) and status counts, which say whether it
     * needs a reconnect.
     */
    public function account(string $accountId): Response
    {
        return $this->send('get', '/accounts?'.http_build_query(['search' => $accountId, 'includeStatusCounts' => 'true']), allowFailure: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPost(array $payload, string $idempotencyKey): Response
    {
        return $this->send('post', '/posts', $payload, ['Idempotency-Key' => $idempotencyKey], allowFailure: true);
    }

    public function getPost(string $postId): Response
    {
        return $this->send('get', "/posts/{$postId}", allowFailure: true);
    }

    /** One LinkedIn post's numbers by URN: impressions, reach, likes, comments, shares, clicks. */
    public function linkedInPostAnalytics(string $accountId, string $urn): Response
    {
        return $this->send('get', "/accounts/{$accountId}/linkedin-post-analytics?".http_build_query(['urn' => $urn]), allowFailure: true);
    }

    /**
     * Page totals for a LinkedIn organization over a date range (at most 89 days,
     * follower numbers end two days ago).
     *
     * @param  list<string>  $metrics
     */
    public function linkedInOrgTotals(string $accountId, string $from, string $to, array $metrics): Response
    {
        return $this->send('get', '/analytics/linkedin/org-aggregate-analytics?'.http_build_query([
            'accountId' => $accountId, 'fromDate' => $from, 'toDate' => $to,
            'metrics' => implode(',', $metrics), 'metricType' => 'total_value',
        ]), allowFailure: true);
    }

    public function retryPost(string $postId): Response
    {
        return $this->send('post', "/posts/{$postId}/retry", [], allowFailure: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, array $payload = [], array $headers = [], bool $allowFailure = false): Response
    {
        $key = (string) config('tantra.zernio.key');
        if ($key === '') {
            throw new ZernioPublishException('Zernio key missing. Add ZERNIO_API_KEY on the server.', ErrorCategory::Permission);
        }

        try {
            $response = $this->request($key, $headers)->{$method}(rtrim((string) config('tantra.zernio.url'), '/').$path, $method === 'get' ? null : $payload);
        } catch (ConnectionException $e) {
            throw new PlatformUnavailableException('Zernio is unreachable: '.$e->getMessage());
        }

        if (! $allowFailure && $response->failed()) {
            throw ZernioPublishException::fromApiResponse($response);
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    private function request(string $key, array $headers): PendingRequest
    {
        return Http::withToken($key)->acceptJson()->asJson()->timeout(60)->withHeaders($headers);
    }
}
