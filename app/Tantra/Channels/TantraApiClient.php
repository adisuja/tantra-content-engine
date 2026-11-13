<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * tantra: the main Tantra app's public API (https://api.usetantra.com/api/v1),
 * read with a member's own API key from the Connectors tab. Only what the
 * Engine needs: who the key belongs to and that person's connected accounts.
 */
final class TantraApiClient
{
    public const DEFAULT_URL = 'https://api.usetantra.com/api/v1';

    public function __construct(private readonly string $key, private readonly string $baseUrl = self::DEFAULT_URL) {}

    /** @return array{email: ?string, name: ?string} */
    public function owner(): array
    {
        $me = (array) $this->get('/auth/profile');

        return ['email' => $me['email'] ?? null, 'name' => trim(($me['firstName'] ?? '').' '.($me['lastName'] ?? '')) ?: null];
    }

    /**
     * Connected social accounts of the key's owner: id, channel, displayName, status.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        $body = $this->get('/channels/accounts');

        return array_values(array_filter(is_array($body) ? (array_is_list($body) ? $body : (array) ($body['items'] ?? $body['data'] ?? [])) : [], 'is_array'));
    }

    private function get(string $path): mixed
    {
        $response = Http::withHeaders(['X-API-Key' => $this->key])->acceptJson()->timeout(30)
            ->get(rtrim($this->baseUrl ?: self::DEFAULT_URL, '/').$path);
        if ($response->failed()) {
            throw new RuntimeException("Tantra API {$path} answered {$response->status()}.");
        }

        return $response->json();
    }
}
