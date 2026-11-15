<?php

declare(strict_types=1);

namespace App\Tantra\Chat;

/**
 * tantra: verifies a Tantra Chat engine token: cs_<HS256 JWT> minted only by the chat Worker (chat-api.usetantra.com)
 * with TANTRA_CHAT_CONTENT_SECRET, a secret the CRM does not hold. Issuer tantra-chat, audience tantra-content,
 * lifetime at most five minutes. Returns the Clerk user id (sub), or null for anything else, including the CRM's own
 * chat tokens (other issuer, audience and secret).
 */
final class ChatToken
{
    private const ISSUER = 'tantra-chat';

    private const AUDIENCE = 'tantra-content';

    private const MAX_LIFETIME = 300;

    private const LEEWAY = 10;

    public static function clerkUserId(string $raw, string $secret): ?string
    {
        if (strlen($secret) < 32 || ! str_starts_with($raw, 'cs_')) {
            return null;
        }
        $parts = explode('.', substr($raw, 3));
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64($h) ?? '', true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$p}", $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $s)) {
            return null;
        }
        $claims = json_decode(self::b64($p) ?? '', true);
        if (! is_array($claims)) {
            return null;
        }
        if (($claims['iss'] ?? null) !== self::ISSUER || ($claims['aud'] ?? null) !== self::AUDIENCE) {
            return null;
        }
        $now = time();
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (! is_int($iat) || ! is_int($exp) || $exp - $iat > self::MAX_LIFETIME || $now > $exp + self::LEEWAY || $iat > $now + self::LEEWAY) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || preg_match('/^user_[A-Za-z0-9]{10,64}$/', $sub) !== 1) {
            return null;
        }

        return $sub;
    }

    private static function b64(string $s): ?string
    {
        $d = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return $d === false ? null : $d;
    }
}
