<?php

declare(strict_types=1);

namespace App\Tantra\UiOff;

use App\Tantra\Crm\CrmPaths;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * tantra: One Content app PRD 4.1 (critic B6), the engine UI shutoff. Every `web` route is in one of two lists:
 *
 *   ALLOW  never touched: OAuth starts and callbacks, the CRM handoff, webhooks, signed public links, MCP sign in
 *   UI     a screen or a screen's action: a GET goes to the matching CRM page (302), anything else answers 410
 *
 * An entry is "METHODS uri", where METHODS is ANY or a list like GET|POST and uri is the route's URI template with *
 * as a wildcard (Str::is). The first UI entry that matches gives the CRM path; {name} in it is the route parameter.
 * tests/Feature/Tantra/OneContent/UiOffAllowlistTest.php reads every route and fails when a web route is in neither list,
 * so a new screen or callback has to be put in one of them on purpose.
 *
 * Routes outside the `web` group (api/*, api/gateway/*, mcp/*, tantra/webhooks/*, the Dodo webhook, tantra/health, up,
 * the OAuth .well-known metadata, tantra/mail-image/*, tantra/media/{media}/file, tantra/digest/unsubscribe/*, the Threads deletion
 * routes, storage/*) never pass the switch at all.
 */
final class UiRoutes
{
    /** Never redirected or refused, switch on or off. */
    public const array ALLOW = [
        // OAuth: each network's sign in start, the pre-connect step and the finish page a CRM-started connect passes through.
        'GET connect/discord',
        'GET connect/facebook',
        'GET connect/google-business',
        'GET connect/instagram',
        'GET connect/instagram-facebook',
        'GET connect/linkedin',
        'GET connect/pinterest',
        'GET connect/threads',
        'GET connect/tiktok',
        'GET connect/x',
        'GET connect/youtube',
        'GET connect/{network}/prepare',
        'POST connect/{network}/request-access',
        // GET goes to the CRM account picker; the engine picker form (a connect started from /content/invite) still posts here.
        'ANY connect/{platform}/finish',
        'GET accounts/*/callback',
        // Canva designs/create, designs/edit, callback, return, returns/{nonce}; Google Photos and Drive start, callback, returns.
        'ANY integrations/*',
        'GET auth/*/callback',
        // Passport: the consent and device screens MCP clients sign in through, and the token refresh.
        'ANY oauth/*',

        // The CRM handoff: the signed connect link and its way back, the sign out frame, the signed Canva link (Write media).
        'GET tantra/crm-connect',
        'GET tantra/crm-connect/back',
        'ANY tantra/embed/signout',
        'GET tantra/canva/start',

        // Webhooks in the web group (the rest are outside it).
        'POST telegram/webhook',

        // Signed or public links.
        'GET previewlink/{token}',
        'GET tantra/billing/{workspace}/manage',
        'GET tantra/billing/{workspace}/restart',
        // The old media file route: it redirects to the signed link.
        'GET media/{media}/file',
        // Discovery files (the OAuth metadata ones are outside the web group).
        'GET .well-known/*',
    ];

    /** Screens and their actions, and the CRM page each GET goes to. First match wins. */
    public const array UI = [
        // Named in PRD 4.1.
        'GET tantra/reconnect/{account}' => '/content/channels?reconnect={account}',
        'ANY tantra/approvals/*' => '/content/review',
        'ANY login' => '/content',
        'ANY register' => '/content',
        'ANY tantra/join/*' => '/content',
        'ANY invites/*' => '/content',

        // Signing in to the engine itself: the CRM signs people in now.
        'ANY logout' => '/content',
        'ANY forgot-password' => '/content',
        'ANY reset-password*' => '/content',
        'ANY verify-email*' => '/content',
        'ANY email/*' => '/content',
        'ANY auth/*/redirect' => '/content',
        'ANY auth/clerk/*' => '/content',
        'ANY tantra/embed' => '/content',
        'ANY tantra/embed/session' => '/content',
        'ANY tantra/connect-window' => '/content',
        'ANY welcome*' => '/content',
        'ANY workspaces*' => '/content',
        'ANY broadcasting/*' => '/content',
        'ANY changelog/*' => '/content',
        'ANY referral-source' => '/content',
        'ANY /' => '/content',

        // Channels.
        'ANY connect/*' => '/content/channels',
        'ANY settings/workspace/channels*' => '/content/channels',
        'ANY settings/workspace/connectors/channels/*' => '/content/channels',
        'ANY channels/*/settings' => '/content/channels',
        'ANY channels/*/posting-schedule*' => '/content/channels',
        'ANY channels/*/queue/*' => '/content/channels',
        'ANY channels/{account}' => '/content/channels',
        'ANY tantra/channels/*/open' => '/content/channels',

        // Insights.
        'ANY insights*' => '/content/insights',
        'ANY channels/*/insights*' => '/content/insights',
        'ANY tantra/insights' => '/content/insights',
        'ANY tantra/channels/*/threads-replies' => '/content/insights',

        // Calendar.
        'ANY schedule*' => '/content/calendar',
        'ANY channels/*/calendar*' => '/content/calendar',
        'ANY channels/*/grid' => '/content/calendar',
        'ANY channels/*/publish' => '/content/calendar',
        'ANY tantra/repost*' => '/content/calendar',

        // Write (the composer and what it reads).
        'ANY posts*' => '/content/create?view=write',
        'ANY media/*' => '/content/create?view=write',
        'ANY discord/*' => '/content/create?view=write',
        'ANY instagram/*' => '/content/create?view=write',
        'ANY pinterest/*' => '/content/create?view=write',
        'ANY tantra/formats/*' => '/content/create?view=write',
        'ANY favicon/*' => '/content/create?view=write',

        // Ideas and Create.
        'ANY create/ideas*' => '/content/create?view=ideas',
        'ANY create/idea-stages*' => '/content/create?view=ideas',
        'ANY create/feed*' => '/content/create?view=ideas',
        'ANY create/templates*' => '/content/create',
        'ANY tantra/templates*' => '/content/create',

        // Review.
        'ANY tantra/review*' => '/content/review',
        'ANY tantra/tasks*' => '/content/review',
        'ANY tantra/ideas/*/to-review' => '/content/review',

        // Repurpose.
        'ANY repurposes*' => '/content/repurpose',
        'ANY tantra/repurposing*' => '/content/repurpose',

        // Team (Brand > Team in the CRM).
        'ANY settings/workspace/members*' => '/content/brand/team',
        'ANY tantra/team*' => '/content/brand/team',
        'ANY tantra/rebrands*' => '/content/brand/team',

        // Engagement, billing and the roadmap have their own CRM pages.
        'ANY tantra/engagement*' => '/engagement',
        'ANY settings/account/billing*' => '/billing',
        'ANY billing/*' => '/billing',
        'ANY subscribe' => '/billing',
        'ANY tantra/billing*' => '/billing',
        'ANY roadmap' => '/roadmap',
        'ANY settings/workspace/roadmap*' => '/roadmap',

        // Settings (everything else under settings, and Studio ops).
        'ANY settings/*' => '/content/settings',
        'ANY tantra/ops*' => '/content/settings',
    ];

    /** Where a UI route with no entry goes (none should: the allowlist test fails first). */
    public const string FALLBACK = '/content';

    public static function enabled(): bool
    {
        return (bool) config('tantra.ui_off.enabled');
    }

    public static function isWeb(Route $route): bool
    {
        return in_array('web', $route->middleware(), true);
    }

    public static function allowed(Route $route, string $method): bool
    {
        return self::firstMatch(self::ALLOW, $route, $method) !== null;
    }

    /** The UI entry this route and method fall under, or null. */
    public static function uiEntry(Route $route, string $method): ?string
    {
        return self::firstMatch(array_keys(self::UI), $route, $method);
    }

    /** The CRM page a UI GET on this route goes to, with its parameters filled in. */
    public static function target(Route $route): string
    {
        $entry = self::uiEntry($route, 'GET');
        $path = $entry === null ? self::FALLBACK : self::UI[$entry];

        $path = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($route): string {
            $value = $route->hasParameter($m[1]) ? $route->originalParameter($m[1]) : null;

            return rawurlencode(is_string($value) ? $value : '');
        }, $path);

        return CrmPaths::base().$path;
    }

    /**
     * Every web route and method, and the list it falls under: allow, ui or none. The allowlist test and the docs read it.
     *
     * @param  iterable<Route>  $routes
     * @return list<array{method: string, uri: string, name: ?string, list: string, entry: ?string}>
     */
    public static function classify(iterable $routes): array
    {
        $out = [];
        foreach ($routes as $route) {
            if (! self::isWeb($route)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $allow = self::firstMatch(self::ALLOW, $route, $method);
                $ui = $allow === null ? self::uiEntry($route, $method) : null;
                $out[] = [
                    'method' => $method,
                    'uri' => $route->uri(),
                    'name' => $route->getName(),
                    'list' => $allow !== null ? 'allow' : ($ui !== null ? 'ui' : 'none'),
                    'entry' => $allow ?? $ui,
                ];
            }
        }

        return $out;
    }

    /** @param  list<string>  $entries */
    private static function firstMatch(array $entries, Route $route, string $method): ?string
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $uri = $route->uri();

        foreach ($entries as $entry) {
            [$methods, $pattern] = explode(' ', $entry, 2);
            if ($methods !== 'ANY' && ! in_array($method, explode('|', $methods), true)) {
                continue;
            }
            if (Str::is($pattern, $uri)) {
                return $entry;
            }
        }

        return null;
    }
}
