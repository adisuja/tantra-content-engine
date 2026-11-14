<?php

declare(strict_types=1);

namespace App\Tantra\Social;

/**
 * tantra: the `signed_request` Meta posts to an app's uninstall and delete
 * callbacks: base64url(signature).base64url(payload), signed with HMAC-SHA256
 * and the app secret. Returns the payload, or null when it is not genuine.
 */
final class MetaSignedRequest
{
    /** A request older than this is a replay: Meta posts the callback when the user acts. */
    private const int MAX_AGE_SECONDS = 86400;

    /**
     * @param  list<?string>|string|null  $secrets  the first secret that verifies it wins
     * @return array<string, mixed>|null
     */
    public static function parse(mixed $signedRequest, array|string|null $secrets): ?array
    {
        if (! is_string($signedRequest) || $signedRequest === '' || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        foreach ((array) $secrets as $secret) {
            if (is_string($secret) && $secret !== '' && ($payload = self::verify($signedRequest, $secret)) !== null) {
                return $payload;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function verify(string $signedRequest, string $secret): ?array
    {
        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);
        $signature = self::decode($encodedSignature);
        $payload = json_decode((string) self::decode($encodedPayload), true, 16, JSON_BIGINT_AS_STRING);

        if ($signature === null || ! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            return null;
        }

        $issuedAt = $payload['issued_at'] ?? null;

        if (is_numeric($issuedAt) && abs(time() - (int) $issuedAt) > self::MAX_AGE_SECONDS) {
            return null;
        }

        return $payload;
    }

    public static function sign(array $payload, string $secret): string
    {
        $encodedPayload = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPayload, $secret, true)), '+/', '-_'), '=').'.'.$encodedPayload;
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
