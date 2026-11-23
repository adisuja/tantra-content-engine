<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Repurpose\Reposts;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;

/**
 * tantra: RF-10, a "connect this network" link for the Tantra app's referral funnel (tantra-crm
 * docs/crm/PRD_2026_11_16_REFERRAL_FUNNEL.md, step 4; docs/crm/engine-patches/rf-10-connect-link/README.md).
 *
 * The Tantra app asks server to server (POST api/tantra/engagement/publish/connect) for one network. The answer is a short lived,
 * signed link to GET tantra/crm-connect: it signs the person in (as the embed does), opens their team's workspace and starts the
 * Engine's own connect route for that network, so the same OAuth apps, consent screens and identity picker run. When the
 * connection finishes, is cancelled or fails, the person goes back to the return URL the Tantra app gave, with the network and
 * the result in the query (?network=x&result=connected|cancelled|failed). Only https://crm.usetantra.com/content/invite and
 * (AF-09, the Channels tab) /content/channels are return URLs; anything else is refused when the link is asked for.
 */
final class CrmConnectLink
{
    /** Where a person may be sent back to: this host, this path, nothing else. */
    public const string RETURN_HOST = 'crm.usetantra.com';

    public const string RETURN_PATH = '/content/invite';

    /** AF-09: Content > Channels connects a network in Tantra too, and gets the person back there. */
    public const array RETURN_PATHS = [self::RETURN_PATH, '/content/channels'];

    public const int TTL_MINUTES = 10;

    private const string SESSION_KEY = 'tantra_crm_connect_return';

    /** The query flag on the connect route that keeps the round trip (the pre-connect step and "Switch account" pass it on). */
    public const string FLAG = 'tantra_crm';

    public const string CONNECTED = 'connected';

    public const string CANCELLED = 'cancelled';

    public const string FAILED = 'failed';

    /**
     * The Tantra app's network keys that connect through a sign in redirect, and the Engine route that starts it.
     * Bluesky (an app password) and Mastodon (a server name) are forms, not a redirect, so they have no link.
     */
    private const array ROUTES = [
        'linkedin' => 'app.social.linkedin.connect',
        'x' => 'app.social.x.connect',
        'facebook' => 'app.social.facebook.connect',
        'instagram' => 'app.social.instagram.connect',
        'threads' => 'app.social.threads.connect',
        'tiktok' => 'app.social.tiktok.connect',
        'youtube' => 'app.social.youtube.connect',
        'pinterest' => 'app.social.pinterest.connect',
    ];

    /** Every network key the Tantra app may ask about (the ones without a link answer no_link). */
    public const array NETWORKS = ['linkedin', 'x', 'facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'pinterest', 'bluesky', 'mastodon'];

    /**
     * The Engine route that starts this network's sign in, or null when the network has no link: a form network, or one
     * whose app has no keys on this instance yet. Instagram signs in directly when that app is on, else through Facebook.
     */
    public static function startRoute(string $network): ?string
    {
        $route = self::ROUTES[$network] ?? null;
        if ($route === null) {
            return null;
        }
        if ($network === 'instagram') {
            return match (true) {
                Platform::Instagram->isEnabled() => 'app.social.instagram.connect',
                Platform::InstagramFacebook->isEnabled() => 'app.social.instagram-facebook.connect',
                default => null,
            };
        }

        return Platform::from($network)->isReadyToConnect() ? $route : null;
    }

    /**
     * The return URL as given, when it is https://crm.usetantra.com/content/invite (any query); else null. No port, no user,
     * no fragment, no other host or path, no control characters.
     */
    public static function safeReturn(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== self::RETURN_HOST
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', self::RETURN_PATHS, true)) {
            return null;
        }

        return $url;
    }

    /** The return URL with the network and the result added (each replaced if the URL had one). */
    public static function withResult(string $url, string $network, string $result): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = [...$query, 'network' => $network, 'result' => $result];

        $path = in_array($parts['path'] ?? '', self::RETURN_PATHS, true) ? $parts['path'] : self::RETURN_PATH;

        return 'https://'.self::RETURN_HOST.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** The signed link the person opens: it carries the person, the workspace, the network and the return URL, and expires. */
    public static function signedUrl(User $user, Workspace $workspace, string $network, string $returnUrl, ?string $instance = null): string
    {
        return URL::temporarySignedRoute('tantra.crm-connect', now()->addMinutes(self::TTL_MINUTES), array_filter([
            'u' => $user->id,
            'w' => $workspace->id,
            'network' => $network,
            'return' => $returnUrl,
            // One Content app PRD 4.1: Mastodon signs in on the person's own server, named in the CRM (signed with the rest).
            'instance' => $instance,
        ], fn (mixed $v): bool => $v !== null));
    }

    /** Keeps where to send the person back for this network until the connection ends. */
    public static function remember(string $network, string $returnUrl): void
    {
        Session::put(self::SESSION_KEY, ['network' => $network, 'url' => $returnUrl, 'expires_at' => now()->addHour()->getTimestamp()]);
    }

    public static function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * Whether the connection of this platform started from a Tantra app link, so it ends back there (backRoute()); any
     * other connection ends on the Engine's own pages, as before.
     */
    public static function owns(?Platform $platform): bool
    {
        $stored = self::stored();

        return $stored !== null && $platform !== null && (Reposts::NETWORK_OF[$platform->value] ?? $platform->value) === $stored['network'];
    }

    /**
     * The Engine page that sends the person back with this result (GET tantra/crm-connect/back). It is a page on this site
     * so an Inertia request (Finish, Back) can follow it and then leave with a full page load.
     */
    public static function backRoute(string $result): string
    {
        return route('tantra.crm-connect.back', ['result' => in_array($result, [self::CONNECTED, self::CANCELLED], true) ? $result : self::FAILED]);
    }

    /** The return URL with the result, once: the round trip ends here. Null when no Tantra app connection is under way. */
    public static function take(string $result): ?string
    {
        $stored = self::stored();
        Session::forget(self::SESSION_KEY);

        return $stored === null ? null : self::withResult($stored['url'], $stored['network'], in_array($result, [self::CONNECTED, self::CANCELLED], true) ? $result : self::FAILED);
    }

    /**
     * One Content app PRD 4.1: the CRM link this connection started from, when it came back to Content > Channels (that page runs the
     * account picker), as {network, url}. Null for any other start.
     *
     * @return array{network: string, url: string}|null
     */
    public static function channelsReturn(?Platform $platform): ?array
    {
        $stored = self::owns($platform) ? self::stored() : null;

        return $stored !== null && parse_url($stored['url'], PHP_URL_PATH) === '/content/channels' ? $stored : null;
    }

    /** @return array{network: string, url: string}|null */
    private static function stored(): ?array
    {
        $stored = Session::get(self::SESSION_KEY);
        if (! is_array($stored) || (int) ($stored['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        $url = self::safeReturn($stored['url'] ?? null);
        $network = $stored['network'] ?? null;

        return $url !== null && in_array($network, self::NETWORKS, true) ? ['network' => $network, 'url' => $url] : null;
    }

    /** The result a failure reason means for the Tantra app: the person said no, or something else stopped it. */
    public static function resultFor(?string $reason): string
    {
        return match ($reason) {
            'cancelled' => self::CANCELLED,
            // Every account the sign in offered is already here: the network is connected.
            'all_connected' => self::CONNECTED,
            default => self::FAILED,
        };
    }
}
