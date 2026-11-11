<?php

declare(strict_types=1);

namespace App\Tantra\Zernio;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;

/**
 * tantra: TryPost's connection check for a Zernio channel (PRD 10.4). A missing
 * key or a Zernio account that is gone or disconnected marks the channel as
 * needing attention, so scheduling stops with a clear message instead of a
 * silent failure at publish time.
 */
final class ZernioVerifier
{
    /**
     * @throws TokenExpiredException
     * @throws PlatformUnavailableException
     */
    public static function verify(SocialAccount $account): bool
    {
        if (! ZernioClient::configured()) {
            throw new TokenExpiredException('Zernio key missing. Add ZERNIO_API_KEY on the server.');
        }

        $response = app(ZernioClient::class)->account((string) $account->platform_user_id);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new TokenExpiredException('The Zernio key was refused.');
        }
        if ($response->failed()) {
            throw new PlatformUnavailableException("Zernio account lookup failed ({$response->status()}).", $response->status());
        }

        $found = collect((array) $response->json('accounts'))->first(fn ($a): bool => is_array($a) && (($a['_id'] ?? $a['id'] ?? null) === $account->platform_user_id));
        if ($found === null) {
            throw new TokenExpiredException('This page is no longer connected in Zernio.');
        }
        if ((int) $response->json('statusCounts.disconnected', 0) > 0) {
            throw new TokenExpiredException('Reconnect the LinkedIn page in Zernio.');
        }

        return true;
    }
}
