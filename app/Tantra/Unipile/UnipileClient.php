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
