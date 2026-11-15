<?php

declare(strict_types=1);

namespace App\Tantra\Chat;

/**
 * tantra: verifies a Tantra Chat token (cs_<HS256 JWT>), minted by the CRM and the chat Worker with the shared
 * TANTRA_CHAT_SIGNING_SECRET. Returns the verified email, or null for anything forged, expired or malformed.
 */
final class ChatToken
{
    private const ISSUER = 'tantra-crm';

    private const AUDIENCE = 'tantra-chat';

    private const LEEWAY = 10;

    public static function email(string $raw, string $secret): ?string
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
        $now = time();
        $aud = $claims['aud'] ?? null;
        $audOk = $aud === self::AUDIENCE || (is_array($aud) && in_array(self::AUDIENCE, $aud, true));
        if (($claims['iss'] ?? null) !== self::ISSUER || ! $audOk) {
            return null;
        }
        if (! is_int($claims['exp'] ?? null) || $now > $claims['exp'] + self::LEEWAY) {
            return null;
        }
        if (isset($claims['nbf']) && (! is_int($claims['nbf']) || $now < $claims['nbf'] - self::LEEWAY)) {
            return null;
        }
        $email = $claims['em'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private static function b64(string $s): ?string
    {
        $d = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return $d === false ? null : $d;
    }
}
