<?php

declare(strict_types=1);

namespace App\Tantra\Embed;

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
     */
    public static function safePath(mixed $path, string $fallback = self::DEFAULT_PATH): string
    {
        if (! is_string($path) || $path === '' || strlen($path) > self::MAX_PATH) {
            return $fallback;
        }
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }
        if (str_contains($path, '\\') || str_contains($path, '://') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $fallback;
        }

        return $path;
    }
}
