<?php

declare(strict_types=1);

namespace App\Tantra\Media;

use App\Enums\Media\CanvaPreset;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Crm\CrmPaths;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;

/**
 * tantra: One Content app PRD 4.1, "Design in Canva" from the CRM's Write media picker.
 *
 *   1. The CRM asks GET /api/gateway/tantra/canva/start?return=<CRM url>&preset=<size> (GatewayCanvaController). The answer is a
 *      short lived, signed link to GET tantra/canva/start and the nonce the result comes back under.
 *   2. The link signs the person in once (LinkSignIn), keeps the return URL for that nonce and opens the engine's own Canva route
 *      (integrations/canva/designs/create), so the same Canva app, sign in and export run.
 *   3. When Canva sends the person back, CanvaController hands off here: the person goes to the return URL with
 *      ?canva=done|failed&nonce=<nonce>&import=<import id>. The CRM reads the import over GET /api/gateway/tantra/media/imports/{import}.
 *
 * Return URLs: https on the CRM host (TANTRA_CRM_APP_URL), under /content, nothing else.
 */
final class CanvaLink
{
    public const int TTL_MINUTES = 10;

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    private const string SESSION_KEY = 'tantra_canva_crm_return';

    public static function safeReturn(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $host = strtolower((string) parse_url(CrmPaths::base(), PHP_URL_HOST));
        $path = (string) ($parts['path'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== $host
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! ($path === '/content' || str_starts_with($path, '/content/')) || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }

        return $url;
    }

    public static function signedUrl(User $user, Workspace $workspace, CanvaPreset $preset, string $nonce, string $returnUrl): string
    {
        return URL::temporarySignedRoute('tantra.canva.start', now()->addMinutes(self::TTL_MINUTES), [
            'u' => $user->id,
            'w' => $workspace->id,
            'preset' => $preset->value,
            'nonce' => $nonce,
            'return' => $returnUrl,
        ]);
    }

    public static function remember(string $nonce, string $returnUrl): void
    {
        $kept = Session::get(self::SESSION_KEY);
        $kept = is_array($kept) ? array_filter($kept, fn (mixed $v): bool => is_array($v) && (int) ($v['expires_at'] ?? 0) >= now()->getTimestamp()) : [];
        $kept[$nonce] = ['url' => $returnUrl, 'expires_at' => now()->addHour()->getTimestamp()];
        Session::put(self::SESSION_KEY, array_slice($kept, -5, null, true));
    }

    /** The CRM URL to finish on for this nonce, once, with the result added. Null when the popup did not start from the CRM. */
    public static function take(?string $nonce, bool $success, ?string $importId = null): ?string
    {
        $kept = Session::get(self::SESSION_KEY);
        if ($nonce === null || ! is_array($kept) || ! is_array($kept[$nonce] ?? null)) {
            return null;
        }
        $entry = $kept[$nonce];
        unset($kept[$nonce]);
        Session::put(self::SESSION_KEY, $kept);
        $url = self::safeReturn($entry['url'] ?? null);
        if ($url === null || (int) ($entry['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return self::withResult($url, $nonce, $success ? self::DONE : self::FAILED, $importId);
    }

    public static function withResult(string $url, string $nonce, string $result, ?string $importId = null): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = [...$query, 'canva' => $result, 'nonce' => $nonce];
        unset($query['import']);
        if ($importId !== null) {
            $query['import'] = $importId;
        }

        return 'https://'.$parts['host'].$parts['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
