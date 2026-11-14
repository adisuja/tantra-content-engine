<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use Illuminate\Support\Str;

/**
 * tantra: the link rule for content (card 29 context). Pure functions, no I/O.
 *
 * Every link in a piece becomes a CRM short link for that piece, person,
 * platform and placement, with utm_source=<platform>, utm_medium=<post |
 * comment | carousel | giveaway>, utm_campaign=enterprise-webinar and
 * utm_content=<code>, where the code is <key>-<short-slug>. A link in a
 * LinkedIn post body that shows a preview card keeps the full jointantra.com
 * UTM link instead, until the CRM can preview go.usetantra.com.
 */
final class LinkRules
{
    /** Finds http(s) links and bare links to the domains we always track. */
    public const URL_PATTERN = '~(?:https?://|\b(?=(?:www\.)?(?:jointantra\.com|webinar\.sellwithtantra\.com|growthclub\.org)\b))[^\s<>"\'\]\)]+~i';

    public const MEDIUMS = ['post', 'comment', 'carousel', 'giveaway'];

    /** Codes and utm_content values the profile links already use (card 29). */
    private const RESERVED_SUFFIXES = ['fw', 'fc', 'ww', 'wc', 'aw', 'ac', 'rw', 'rc', 'btn',
        'featured-workshop', 'featured-consultation', 'website-workshop', 'website-consultation',
        'about-workshop', 'about-consultation', 'role-workshop', 'role-consultation'];

    private const STOP_WORDS = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'from', 'with', 'by',
        'vs', 'your', 'you', 'is', 'are', 'every', 'one', 'what', 'how', 'why', 'that', 'this', 'it', 'at', 'after'];

    /** Platform as the CRM channel and utm_source: every LinkedIn variant is linkedin, every Instagram variant instagram. */
    public static function platform(string $platform): string
    {
        $p = strtolower($platform);

        return match (true) {
            str_contains($p, 'linkedin') => 'linkedin',
            str_contains($p, 'instagram') => 'instagram',
            str_contains($p, 'facebook') => 'facebook',
            $p === 'twitter' => 'x',
            str_starts_with($p, 'youtube') => 'youtube',
            default => $p,
        };
    }

    /** Short platform tag for codes; LinkedIn, the home platform, has none. */
    public static function platformTag(string $platform): string
    {
        return match (self::platform($platform)) {
            'linkedin' => '',
            'facebook' => 'fb',
            'instagram' => 'ig',
            'youtube' => 'yt',
            'tiktok' => 'tt',
            'threads' => 'th',
            'pinterest' => 'pin',
            'bluesky' => 'bsky',
            'mastodon' => 'mast',
            default => Str::limit(self::platform($platform), 4, ''),
        };
    }

    /** @return list<string> */
    public static function urls(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $m);

        return array_values(array_map(fn (string $u): string => self::trimUrl($u), $m[0]));
    }

    /** Drops sentence punctuation stuck to the end of a link. */
    public static function trimUrl(string $url): string
    {
        return rtrim($url, '.,;:!?…');
    }

    public static function host(string $url): string
    {
        $withScheme = preg_match('~^https?://~i', $url) ? $url : 'https://'.$url;

        return strtolower((string) parse_url($withScheme, PHP_URL_HOST));
    }

    /** Whether a link is one we rewrite: anything not already tracked, and not the app's own or a LinkedIn profile link. */
    public static function needsTracking(string $url, callable $isOurFullLink): bool
    {
        $host = self::host($url);
        if ($host === '' || in_array($host, self::config('skip_hosts', []), true)) {
            return false;
        }
        // A media or app link the Engine itself hosts is never content.
        if (str_ends_with($host, '.usetantra.com') && $host !== 'crm.usetantra.com') {
            return false;
        }

        // A full jointantra.com UTM link we wrote for a LinkedIn preview card is already tracked.
        return ! (self::isOrganic($url) && $isOurFullLink(self::query($url)['utm_content'] ?? null));
    }

    public static function isOrganic(string $url): bool
    {
        return in_array(self::host($url), self::config('organic_hosts', []), true);
    }

    /**
     * Where the short link sends people: organic hosts and the retired brand go to
     * jointantra.com (same path), with old UTMs dropped because the CRM sets them.
     */
    public static function destination(string $url): string
    {
        $withScheme = preg_match('~^https?://~i', $url) ? $url : 'https://'.$url;
        $host = self::host($withScheme);
        $parts = parse_url($withScheme) ?: [];
        $query = array_filter(self::query($withScheme), fn (string $k): bool => ! str_starts_with(strtolower($k), 'utm_'), ARRAY_FILTER_USE_KEY);
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        if (in_array($host, self::config('retired_hosts', []), true)) {
            return (string) self::config('organic', 'https://jointantra.com/');
        }

        $base = in_array($host, self::config('organic_hosts', []), true)
            ? rtrim((string) self::config('organic', 'https://jointantra.com/'), '/')
            : 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '/';

        return $base.($path === '' ? '/' : $path).($query !== [] ? '?'.http_build_query($query) : '').$fragment;
    }

    /** @return array<string, string> */
    public static function query(string $url): array
    {
        $withScheme = preg_match('~^https?://~i', $url) ? $url : 'https://'.$url;
        parse_str((string) parse_url($withScheme, PHP_URL_QUERY), $q);

        return array_map(fn (mixed $v): string => is_array($v) ? (string) reset($v) : (string) $v, $q);
    }

    /** A readable slug of the piece: up to three meaningful words, at most 20 characters. */
    public static function slug(string $name): string
    {
        $words = array_values(array_filter(
            explode('-', Str::slug(Str::ascii($name))),
            fn (string $w): bool => $w !== '' && ! in_array($w, self::STOP_WORDS, true),
        ));
        $slug = '';
        foreach (array_slice($words, 0, 3) as $w) {
            $next = $slug === '' ? $w : $slug.'-'.$w;
            if (strlen($next) > 20) {
                break;
            }
            $slug = $next;
        }

        return $slug !== '' ? $slug : 'piece';
    }

    /**
     * <key>-<slug>[-<platform tag>]-<medium>, 3 to 40 characters, never one of
     * the profile codes. $n > 1 adds a counter for a code already taken.
     */
    public static function code(string $memberKey, string $pieceName, string $platform, string $placement, string $medium, int $n = 1): string
    {
        $key = Str::slug($memberKey) ?: 'team';
        $tag = self::platformTag($platform);
        $where = $placement === $medium || in_array($placement, ['post', 'comment'], true) ? $medium : $placement;
        $tail = implode('-', array_filter([$tag, Str::slug($where), $n > 1 ? (string) $n : '']));
        $room = 40 - strlen($key) - strlen($tail) - 2;
        $slug = rtrim(substr(self::slug($pieceName), 0, max(3, $room)), '-');
        $code = trim(preg_replace('/-+/', '-', "{$key}-{$slug}-{$tail}") ?? '', '-');
        if (in_array(Str::after($code, $key.'-'), self::RESERVED_SUFFIXES, true)) {
            $code .= '-x';
        }

        return substr($code, 0, 40);
    }

    /** @return array<string, string> */
    public static function utm(string $platform, string $medium, string $code): array
    {
        return [
            'utm_source' => self::platform($platform),
            'utm_medium' => $medium,
            'utm_campaign' => (string) self::config('utm_campaign', 'enterprise-webinar'),
            'utm_content' => $code,
        ];
    }

    /** The full jointantra.com UTM link, for a LinkedIn post body that needs its preview card. */
    public static function fullLink(string $destination, array $utm): string
    {
        $sep = str_contains($destination, '?') ? '&' : '?';
        [$base, $fragment] = array_pad(explode('#', $destination, 2), 2, null);

        return $base.$sep.http_build_query($utm).($fragment !== null ? '#'.$fragment : '');
    }

    /**
     * Whether this placement keeps the full link: a link in the body of a LinkedIn
     * post whose first link shows a preview card. Only the first body link
     * makes the card, so only it is kept long.
     */
    public static function keepsFullLink(string $platform, string $placement, bool $previewCard, bool $firstLinkInText, string $destination): bool
    {
        return self::platform($platform) === 'linkedin'
            && $placement === 'post'
            && $previewCard
            && $firstLinkInText
            && self::isOrganic($destination);
    }

    private static function config(string $key, mixed $default = null): mixed
    {
        return config('tantra.crm.links.'.$key, $default);
    }
}
