<?php

declare(strict_types=1);

namespace App\Tantra\Embed;

use Illuminate\Http\Request;

/**
 * tantra: the Engine inside the Tantra CRM's Content tab (PRD 2026-11-15).
 *
 * The CRM frames GET tantra/embed, hands it the person's Clerk session token
 * by postMessage, and the boot page trades it for a Laravel session
 * (EmbedController). The Vue side (resources/js/lib/tantra/embed.ts) decides
 * embedded mode by itself (window.self !== window.top); nothing here changes
 * a page opened in its own tab.
 */
class Embed
{
    public const DEFAULT_PATH = '/tantra/insights';

    public const MAX_PATH = 2000;

    /** The connect popup's nonce (resources/js/lib/tantra/embed.ts createConnectNonce). */
    public const CONNECT_NONCE = '/^[A-Za-z0-9_-]{16,64}$/';

    public static function enabled(): bool
    {
        return (bool) config('tantra.embed.enabled');
    }

    /**
     * The origins allowed to frame the Engine (TANTRA_EMBED_PARENTS). Only a
     * bare scheme://host[:port] is kept, so a typo can never break the header.
     *
     * @return list<string>
     */
    public static function parents(): array
    {
        $origins = [];
        foreach ((array) config('tantra.embed.parents', []) as $origin) {
            $origin = rtrim(trim((string) $origin), '/');
            if (preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#i', $origin) === 1) {
                $origins[] = strtolower($origin);
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * Where the boot page goes once signed in: a path on this site only.
     * Anything else (another host, a scheme, a backslash, a control
     * character, too long) falls back to Insights. Blocks open redirects.
     * The connect popup's bootstrap page uses the same rule.
     */
    public static function safePath(mixed $path, string $fallback = self::DEFAULT_PATH): string
    {
        if (! is_string($path) || $path === '' || strlen($path) > self::MAX_PATH) {
            return $fallback;
        }
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $fallback;
        }

        // A scheme or host only counts where it changes where the path goes,
        // not inside a query value such as ?next=https://example.com.
        $parts = parse_url('https://engine.invalid'.$path);
        if ($parts === false || ($parts['host'] ?? null) !== 'engine.invalid' || isset($parts['port']) || isset($parts['user'])) {
            return $fallback;
        }

        return $path;
    }

    /**
     * Paths that must open in the standalone app, never inside Tantra: OAuth and connect flows (every provider
     * callback stays on this host), sign in and set password pages, signed links, the embed pages themselves,
     * public previews, media and unsubscribe links.
     */
    private const STANDALONE = '#^/(auth|login|logout|register|forgot-password|reset-password|email|join|connect|accounts|social|oauth|tantra/embed|tantra/connect-window|tantra/crm-connect|tantra/reconnect|tantra/join|previewlink|p/|media|storage|unsubscribe|webhooks?|api/)#';

    /**
     * A link from an Engine email, opened inside Tantra (S-12): https://content.usetantra.com/schedule?x=1 becomes
     * https://crm.usetantra.com/content?p=%2Fschedule%3Fx%3D1. Off (TANTRA_EMBED_EMAIL_LINKS) or for any other
     * host, a standalone path, a signed link or a path that is not safe, the link is returned as it was.
     */
    public static function shellLink(?string $url): ?string
    {
        if ($url === null || ! self::enabled() || ! (bool) config('tantra.embed.email_links')) {
            return $url;
        }

        return self::shellUrl($url) ?? $url;
    }

    /**
     * C-10: where the standalone sign in page sends a person instead (TANTRA_EMBED_STANDALONE_REDIRECT), or null
     * to show the page as before. Inside the Tantra frame (Sec-Fetch-Dest: iframe), for ?password=1 and when the
     * page the person was going to is a standalone path (an OAuth or connect flow), the sign in page stays.
     */
    public static function loginRedirect(Request $request): ?string
    {
        if (! self::enabled() || ! (bool) config('tantra.embed.standalone_redirect') || $request->boolean('password')) {
            return null;
        }
        if (strtolower((string) $request->headers->get('Sec-Fetch-Dest')) === 'iframe') {
            return null;
        }
        $shell = (string) config('tantra.embed.shell_url');
        if ($shell === '') {
            return null;
        }
        $intended = $request->hasSession() ? $request->session()->get('url.intended') : null;
        if (! is_string($intended) || $intended === '') {
            return $shell;
        }
        $path = parse_url($intended, PHP_URL_PATH);
        if (is_string($path) && preg_match(self::STANDALONE, $path) === 1) {
            return null;
        }

        return self::shellUrl($intended) ?? $shell;
    }

    /**
     * A page on this site as a link inside Tantra (shell_url?p=<path>), or null for another host, a standalone
     * path, the home page, a signed link or a path that is not safe.
     */
    private static function shellUrl(string $url): ?string
    {
        $shell = (string) config('tantra.embed.shell_url');
        $app = parse_url((string) config('app.url'));
        $parts = parse_url($url);
        if ($shell === '' || $parts === false || ! isset($parts['host'], $app['host']) || strcasecmp($parts['host'], $app['host']) !== 0) {
            return null;
        }
        if (isset($parts['port']) || isset($parts['user']) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)) {
            return null;
        }
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        if ($path === '/' || preg_match(self::STANDALONE, $path) === 1) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        if (array_key_exists('signature', $query) || array_key_exists('token', $query)) {
            return null;
        }
        $full = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
        if (self::safePath($full, '') === '') {
            return null;
        }

        return $shell.'?p='.rawurlencode($full).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /** Every href in an email's HTML, through shellLink(). The rest of the HTML is left exactly as it was. */
    public static function shellLinks(?string $html): ?string
    {
        if ($html === null || ! self::enabled() || ! (bool) config('tantra.embed.email_links')) {
            return $html;
        }

        return preg_replace_callback('/\bhref=(["\'])(.*?)\1/i', function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            $link = self::shellLink($url);

            return $link === $url ? $m[0] : 'href='.$m[1].htmlspecialchars((string) $link, ENT_QUOTES | ENT_HTML5).$m[1];
        }, $html) ?? $html;
    }
}
