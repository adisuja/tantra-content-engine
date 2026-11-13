<?php

declare(strict_types=1);

namespace App\Tantra\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * tantra: verifies a Clerk session token the way usetantra.com's backend does,
 * without a call to Clerk per sign in: RS256 against the instance's public
 * JWKS ({frontend_api}/.well-known/jwks.json, cached), then exp and nbf, the
 * issuer (must be the Frontend API) and azp (must be an authorized party).
 */
class ClerkToken
{
    private const JWKS_TTL = 3600;

    private const REFETCH_EVERY = 60;

    /**
     * @return array<string, mixed> The verified claims.
     *
     * @throws InvalidClerkToken
     */
    public static function verify(string $token): array
    {
        $issuer = (string) config('tantra.clerk.frontend_api');
        $kid = self::kid($token);

        $keys = self::keys($issuer);
        if (! isset($keys[$kid]) && Cache::add('tantra:clerk:jwks-refetch:'.md5($issuer), true, self::REFETCH_EVERY)) {
            // Clerk may have rotated its key: read the set again, at most once a minute.
            $keys = self::keys($issuer, fresh: true);
        }
        if (! isset($keys[$kid])) {
            throw new InvalidClerkToken('Unknown signing key.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = (int) config('tantra.clerk.leeway', 5);
        try {
            $claims = (array) JWT::decode($token, $keys);
        } catch (Throwable $e) {
            throw new InvalidClerkToken('The session token did not verify: '.$e->getMessage(), previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (! isset($claims['exp'])) {
            throw new InvalidClerkToken('The session token has no expiry.');
        }
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer) {
            throw new InvalidClerkToken('The session token was issued by another Clerk instance.');
        }
        $parties = (array) config('tantra.clerk.authorized_parties', []);
        if (! isset($claims['azp']) || ! in_array(rtrim((string) $claims['azp'], '/'), $parties, true)) {
            throw new InvalidClerkToken('The session token was made for another site.');
        }
        // A session still waiting on a second step (sts "pending") is not signed in yet.
        if (($claims['sts'] ?? null) === 'pending') {
            throw new InvalidClerkToken('The session is not complete yet.');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new InvalidClerkToken('The session token names no user.');
        }

        return json_decode((string) json_encode($claims), true);
    }

    private static function kid(string $token): string
    {
        $parts = explode('.', $token);
        $header = count($parts) === 3 ? json_decode((string) JWT::urlsafeB64Decode($parts[0]), true) : null;
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new InvalidClerkToken('Not a Clerk session token.');
        }

        return $header['kid'];
    }

    /**
     * @return array<string, Key>
     */
    private static function keys(string $issuer, bool $fresh = false): array
    {
        $cacheKey = 'tantra:clerk:jwks:'.md5($issuer);
        $cached = Cache::get($cacheKey);
        if (! $fresh && is_array($cached)) {
            return self::parse($cached);
        }

        // Fetch first; the cached set is replaced only by a set that parses.
        try {
            $response = Http::timeout(5)->acceptJson()->get($issuer.'/.well-known/jwks.json');
            $set = $response->successful() ? $response->json() : null;
            $keys = is_array($set) && is_array($set['keys'] ?? null) ? self::parse($set) : null;
        } catch (Throwable) {
            $keys = null;
        }

        if ($keys === null) {
            if (is_array($cached)) {
                return self::parse($cached);
            }

            throw new InvalidClerkToken('Clerk\'s signing keys could not be read.');
        }

        Cache::put($cacheKey, $set, self::JWKS_TTL);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $set
     * @return array<string, Key>
     */
    private static function parse(array $set): array
    {
        try {
            return JWK::parseKeySet($set, 'RS256');
        } catch (Throwable $e) {
            throw new InvalidClerkToken('Clerk\'s signing keys could not be read.', previous: $e);
        }
    }
}
