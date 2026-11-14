<?php

declare(strict_types=1);

namespace App\Tantra\Mail;

use App\Enums\SocialAccount\Platform;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * tantra: the shared design tokens and helpers behind every Engine email
 * (app/Tantra/views/mail/kit). Palette from usetantra.com: ink on cool grey,
 * one violet accent, green for good news, amber for "act soon", red for broken.
 */
final class Kit
{
    public const string FONT = "Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";

    /** Light palette (the inline default). */
    public const array C = [
        'canvas' => '#f3f4f8', 'card' => '#ffffff', 'sub' => '#f7f8fb', 'line' => '#e5e7ef',
        'ink' => '#12131a', 'body' => '#3a3f4e', 'muted' => '#6b7285',
        'accent' => '#6d4aff', 'accent_ink' => '#ffffff', 'chip' => '#efebff',
        'good' => '#0f9f6e', 'warn' => '#b45309', 'bad' => '#d92d20', 'hero' => '#12131a',
    ];

    /** Dark palette (prefers-color-scheme: dark, Outlook.com [data-ogsc]). */
    public const array D = [
        'canvas' => '#0b0c10', 'card' => '#15171e', 'sub' => '#1c1f28', 'line' => '#2a2e3a',
        'ink' => '#f4f5f9', 'body' => '#c9cdd8', 'muted' => '#8d94a6',
        'accent' => '#a593ff', 'chip' => '#262046', 'hero' => '#1c1f28',
    ];

    public static function appUrl(string $path = '/'): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /** A file under public/, on the app's own domain (content.usetantra.com in production). */
    public static function asset(string $path): string
    {
        return self::appUrl($path);
    }

    public static function settingsUrl(): string
    {
        return Route::has('app.notifications.preferences')
            ? route('app.notifications.preferences')
            : self::appUrl('/settings/profile/notifications');
    }

    public static function networkOf(Platform|string|null $platform): string
    {
        if ($platform instanceof Platform) {
            return $platform->network();
        }

        $value = strtolower((string) $platform);

        return Platform::tryFrom($value)?->network() ?? ($value !== '' ? $value : 'linkedin');
    }

    public static function networkLabel(Platform|string|null $platform): string
    {
        return match (self::networkOf($platform)) {
            'linkedin' => 'LinkedIn', 'instagram' => 'Instagram', 'x' => 'X', 'facebook' => 'Facebook',
            'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'threads' => 'Threads', 'pinterest' => 'Pinterest',
            'bluesky' => 'Bluesky', 'mastodon' => 'Mastodon', 'telegram' => 'Telegram', 'discord' => 'Discord',
            'google_business' => 'Google Business', default => Str::headline(self::networkOf($platform)),
        };
    }

    public static function networkIcon(Platform|string|null $platform): string
    {
        $network = self::networkOf($platform);
        $known = ['bluesky', 'discord', 'facebook', 'google_business', 'instagram', 'linkedin', 'mastodon', 'pinterest', 'telegram', 'threads', 'tiktok', 'x', 'youtube'];

        return self::asset('images/accounts/'.(in_array($network, $known, true) ? $network : 'linkedin').'.png');
    }

    /** Plain text, trimmed to a length on a word boundary, for clamped copy. */
    public static function clamp(?string $text, int $chars): array
    {
        $text = trim((string) $text);
        if (mb_strlen($text) <= $chars) {
            return [$text, false];
        }

        return [rtrim(Str::of(mb_substr($text, 0, $chars))->beforeLast(' ')->value(), " ,.;:\n").'...', true];
    }

    /** Compact numbers for stat tiles: 1,240 / 12.4K / 3.1M. */
    public static function compact(int|float $n): string
    {
        $n = (float) $n;

        return match (true) {
            abs($n) >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.').'M',
            abs($n) >= 10_000 => rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.').'K',
            default => number_format($n),
        };
    }
}
