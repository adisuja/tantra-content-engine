<?php

declare(strict_types=1);

namespace App\Tantra\Unipile;

use App\Exceptions\PlatformUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * tantra: Unipile V2 for team engagement (PRD 10.5): read a post, react,
 * comment, reply in a comment's thread, list comments. Server side only; the
 * key is config('tantra.unipile.key'), sent as X-API-KEY.
 */
class UnipileClient
{
    public static function configured(): bool
    {
        return filled(config('tantra.unipile.key')) && filled(config('tantra.unipile.dsn'));
    }

    /** @return list<array<string, mixed>> */
    public function accounts(): array
    {
        $response = $this->get('/accounts');

        return array_values((array) ($response->json('data') ?? $response->json('items') ?? []));
    }

    /** Unipile's own id for a LinkedIn post, from a native id (ugcPost:..., activity:...) or a URN. */
    public function postId(string $accountId, string $nativeId): ?string
    {
        $native = preg_replace('/^urn:li:/', '', $nativeId);
        $response = $this->get("/{$accountId}/posts/".rawurlencode((string) $native), allowFailure: true);

        return $response->successful() ? ((string) $response->json('id') ?: null) : null;
    }

    /**
     * A post as Unipile sees it through its author's account: reactions_counter,
     * comments_counter, reposts_counter. No impressions (Spike S2).
     *
     * @return array<string, mixed>|null
     */
    public function postDetails(string $accountId, string $nativeId): ?array
    {
        $native = preg_replace('/^urn:li:/', '', $nativeId);
        $response = $this->get("/{$accountId}/posts/".rawurlencode((string) $native), allowFailure: true);

        return $response->successful() ? (array) $response->json() : null;
    }

    public function react(string $accountId, string $postId, string $reaction = 'linkedin_like'): Response
    {
        return $this->post("/{$accountId}/posts/{$postId}/reactions", ['reaction' => $reaction]);
    }

    /** @return string the new comment's id */
    public function comment(string $accountId, string $postId, string $text): string
    {
        return (string) $this->post("/{$accountId}/posts/{$postId}/comments", ['text' => $text])->json('id');
    }

    /** @return string the reply's id */
    public function reply(string $accountId, string $postId, string $commentId, string $text): string
    {
        return (string) $this->post("/{$accountId}/posts/{$postId}/comments/{$commentId}", ['text' => $text])->json('id');
    }

    /** @return array<string, mixed>|null the account's own LinkedIn profile (users/me is rate limited) */
    public function me(string $accountId): ?array
    {
        return $this->profile($accountId)['data'];
    }

    /**
     * The account's own LinkedIn profile with the HTTP status, so a disconnected
     * account (401) can be told from a busy one (429).
     *
     * @return array{status: int, data: array<string, mixed>|null}
     */
    public function profile(string $accountId): array
    {
        $response = $this->get("/{$accountId}/users/me", allowFailure: true);

        return ['status' => $response->status(), 'data' => $response->successful() ? (array) $response->json() : null];
    }

    /**
     * One page of the account's own LinkedIn posts, newest first (GET users/me/posts),
     * each with reactions_counter, comments_counter, reposts_counter and
     * analytics.impressions_counter, plus the cursor of the next page. Failures come
     * back as the response, so Insights can tell a busy account from a dropped one.
     */
    public function ownPosts(string $accountId, int $limit = 50, ?string $cursor = null): Response
    {
        $query = http_build_query(array_filter(['limit' => $limit, 'cursor' => $cursor], fn ($value): bool => $value !== null && $value !== ''));

        return $this->get("/{$accountId}/users/me/posts?{$query}", allowFailure: true);
    }

    /** The LinkedIn member id of the account itself, to tell its own comments apart. */
    public function ownId(string $accountId): ?string
    {
        $response = $this->get("/{$accountId}/users/me", allowFailure: true);

        return $response->successful() ? ((string) ($response->json('provider_id') ?? $response->json('id')) ?: null) : null;
    }

    /** @return list<array<string, mixed>> */
    public function comments(string $accountId, string $postId, int $limit = 100): array
    {
        return array_values((array) ($this->get("/{$accountId}/posts/{$postId}/comments?limit={$limit}")->json('data') ?? []));
    }

    /** @return list<array<string, mixed>> the replies under one comment */
    public function replies(string $accountId, string $postId, string $commentId): array
    {
        $response = $this->get("/{$accountId}/posts/{$postId}/comments/{$commentId}/replies", allowFailure: true);

        return $response->successful() ? array_values((array) ($response->json('data') ?? [])) : [];
    }

    /**
     * Every account, and whether the list is known to be complete. Unipile v2
     * pages with `has_more` but takes no cursor, so a list that says there is
     * more is never used to mark anything disconnected.
     *
     * @return array{items: list<array<string, mixed>>, complete: bool}
     */
    public function allAccounts(): array
    {
        $response = $this->get('/accounts?limit=250');

        return [
            'items' => array_values((array) ($response->json('data') ?? $response->json('items') ?? [])),
            'complete' => $response->json('has_more') !== true,
        ];
    }

    /**
     * Create a post on the account (v2 POST /{account}/posts). HTTP failures come
     * back as the response and connection failures as the exception, so the
     * publisher can tell "never sent" from "maybe posted".
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function createPost(string $accountId, array $payload): Response
    {
        if (! self::configured()) {
            throw new RuntimeException('LinkedIn publishing is not set up on the server.');
        }

        return $this->request()->timeout(180)->post(rtrim((string) config('tantra.unipile.dsn'), '/')."/{$accountId}/posts", $payload);
    }

    /**
     * One account's state, from the list: connected or disconnected.
     *
     * @param  array<string, mixed>  $account
     */
    public static function statusOf(array $account): string
    {
        $raw = is_string($account['status'] ?? null) ? $account['status'] : (string) data_get($account, 'sources.0.status', '');

        return in_array(strtoupper($raw), ['OK', 'RUNNING', 'CONNECTED', 'CREATION_SUCCESS', 'RECONNECTED', 'SYNC_SUCCESS'], true) ? 'connected' : 'disconnected';
    }

    private function get(string $path, bool $allowFailure = false): Response
    {
        return $this->send('get', $path, null, $allowFailure);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): Response
    {
        return $this->send('post', $path, $body, false);
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body, bool $allowFailure): Response
    {
        if (! self::configured()) {
            throw new RuntimeException('Unipile is not configured (UNIPILE_DSN, UNIPILE_API_KEY).');
        }

        try {
            $response = $this->request()->{$method}(rtrim((string) config('tantra.unipile.dsn'), '/').$path, $body);
        } catch (ConnectionException $e) {
            throw new PlatformUnavailableException('Unipile is unreachable: '.$e->getMessage());
        }

        if (! $allowFailure && $response->failed()) {
            throw new UnipileException($response);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['X-API-KEY' => (string) config('tantra.unipile.key')])->acceptJson()->asJson()->timeout(45);
    }
}
