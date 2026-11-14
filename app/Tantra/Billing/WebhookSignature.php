<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

/**
 * tantra: Standard Webhooks verification (what Dodo signs with). The signed
 * content is "{webhook-id}.{webhook-timestamp}.{raw body}", HMAC-SHA256 with
 * the base64 secret after its "whsec_" prefix; webhook-signature holds one or
 * more "v1,{base64}" entries. Timestamps older or newer than 5 minutes fail,
 * so a captured request cannot be replayed later.
 */
final class WebhookSignature
{
    public const int TOLERANCE_SECONDS = 300;

    public static function verify(string $secret, string $id, string $timestamp, string $signatures, string $body, ?int $now = null): bool
    {
        if ($secret === '' || $id === '' || ! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        foreach (preg_split('/\s+/', trim($signatures)) ?: [] as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** For tests and the local cycle check: the header value Dodo would send. */
    public static function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        $key = (string) base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret);

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }
}
