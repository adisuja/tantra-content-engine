<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// tantra: the Engine inside the Tantra CRM's Content tab (PRD 2026-11-15, 4.3
// and 4.4), driven in a real browser. A tiny parent page stands in for the
// CRM: it frames /tantra/embed, answers tantra:ready with a Clerk session
// token and records every message the frame sends. It is served on the same
// origin as the Engine, which the test then lists as the only parent.

const BROWSER_EMBED_ISSUER = 'https://clerk.usetantra.com';
const BROWSER_EMBED_KID = 'ins_browser_embed_key';
const BROWSER_EMBED_CRM = 'https://crm.usetantra.com';

/**
 * @return array{private: string, jwks: array<string, mixed>}
 */
function browserEmbedKey(): array
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $private);
    $details = openssl_pkey_get_details($resource);
    $b64 = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return $key = [
        'private' => $private,
        'jwks' => ['keys' => [[
            'use' => 'sig', 'kty' => 'RSA', 'kid' => BROWSER_EMBED_KID, 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]],
    ];
}

function browserEmbedToken(string $sub): string
{
    return JWT::encode([
        'iss' => BROWSER_EMBED_ISSUER,
        'sub' => $sub,
        'azp' => BROWSER_EMBED_CRM,
        'sid' => 'sess_'.$sub,
        'iat' => time() - 10,
        'nbf' => time() - 10,
        'exp' => time() + 900,
    ], browserEmbedKey()['private'], 'RS256', BROWSER_EMBED_KID);
}

/**
 * A ready Engine admin linked to the Clerk user user_priya, with two
 * Bluesky channels (LinkedIn stays free to connect).
 *
 * @return array{0: User, 1: Workspace, 2: SocialAccount, 3: SocialAccount}
 */
function browserEmbedAdmin(): array
{
    $user = User::factory()->create(['email' => 'priya@usetantra.com']);
    $user->forceFill(['clerk_user_id' => 'user_priya'])->save();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $first = SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);
    $second = SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);

    return [$user->fresh(), $workspace, $first, $second];
}

/**
 * Serves the stand-in CRM page at /__tantra-parent and the popup's stand-in
 * opener at /__tantra-opener. Both list their own origin as the only parent.
 */
function serveEmbedParents(): void
{
    $token = json_encode(browserEmbedToken('user_priya'));

    Route::get('/__tantra-parent', function (Request $request) use ($token) {
        config(['tantra.embed.parents' => [$request->getSchemeAndHttpHost()]]);
        $src = json_encode('/tantra/embed?'.http_build_query(['to' => (string) $request->query('to', '/settings/workspace/channels')]));

        return response(<<<HTML
            <!DOCTYPE html>
            <html><head><meta charset="utf-8"><title>Parent</title></head>
            <body style="margin:0">
            <iframe id="engine" style="width:1280px;height:860px;border:0"></iframe>
            <script>
                window.messages = [];
                window.engine = () => document.getElementById('engine');
                window.fdoc = () => window.engine().contentDocument;
                window.fwin = () => window.engine().contentWindow;
                window.frameHas = (selector) => { try { return Boolean(window.fdoc().querySelector(selector)); } catch (e) { return false; } };
                window.frameText = () => { try { return window.fdoc().body.innerText; } catch (e) { return ''; } };
                window.framePath = () => { try { return window.fwin().location.pathname + window.fwin().location.search; } catch (e) { return ''; } };
                window.got = (type) => window.messages.filter((m) => m && m.type === type);
                window.go = (path, extra) => window.fwin().postMessage(Object.assign({ v: 1, type: 'tantra:go', path: path }, extra || {}), location.origin);
                window.addEventListener('message', (event) => {
                    if (event.source !== window.fwin()) return;
                    window.messages.push(event.data);
                    if (event.data && event.data.type === 'tantra:ready') {
                        window.fwin().postMessage({ v: 1, type: 'tantra:auth', token: {$token} }, location.origin);
                    }
                });
                window.engine().src = {$src};
            </script>
            </body></html>
            HTML);
    });

    Route::get('/__tantra-opener', function (Request $request) {
        config(['tantra.embed.parents' => [$request->getSchemeAndHttpHost()]]);
        $url = json_encode((string) $request->query('url'));
        $features = json_encode((string) $request->query('features', 'width=600,height=700'));

        return response(<<<HTML
            <!DOCTYPE html>
            <html><head><meta charset="utf-8"><title>Opener</title></head>
            <body>
            <button id="open" type="button" style="width:200px;height:60px">Open</button>
            <script>
                window.reports = [];
                const channel = new BroadcastChannel('tantra.connect');
                channel.onmessage = (event) => window.reports.push(Object.assign({ via: 'channel' }, event.data));
                window.addEventListener('message', (event) => window.reports.push(Object.assign({ via: 'opener' }, event.data)));
                document.getElementById('open').addEventListener('click', () => {
                    window.popup = window.open({$url}, 'tantra-connect', {$features});
                });
            </script>
            </body></html>
            HTML);
    });
}

/**
 * Opens the stand-in CRM page with the frame on `$to`, signed in.
 */
function openEmbedFrame(string $to = '/settings/workspace/channels'): mixed
{
    $page = visit('/__tantra-parent?'.http_build_query(['to' => $to]));
    waitForCondition($page, "window.got('tantra:session').length > 0 && window.frameHas('[data-testid]')");

    expect($page->script("window.got('tantra:session')[0]"))->toMatchArray(['v' => 1, 'type' => 'tantra:session', 'ok' => true, 'email' => 'priya@usetantra.com']);

    return $page;
}

/**
 * Starts a LinkedIn connection in the frame with window.open stubbed, and
 * returns the nonce the frame gave the popup.
 */
function startFrameConnect(mixed $page): string
{
    waitForCondition($page, "window.frameHas('[data-testid=\"channels-connect\"]')");
    $page->script(<<<'JS'
        (() => {
            const w = window.fwin();
            w.__opened = [];
            w.__popup = { closed: false, focus() {} };
            w.open = (url) => { w.__opened.push(url); return w.__popup; };
            window.fdoc().querySelector('[data-testid="channels-connect"]').click();
            return true;
        })()
    JS);
    waitForCondition($page, "window.frameHas('[data-testid=\"connect-channel-linkedin\"]')");
    $page->script("window.fdoc().querySelector('[data-testid=\"connect-channel-linkedin\"]').click(); true;");
    waitForCondition($page, "window.frameHas('[data-testid=\"connect-panel-continue\"]')");
    $page->script("window.fdoc().querySelector('[data-testid=\"connect-panel-continue\"]').click(); true;");
    waitForCondition($page, 'window.fwin().__opened.length === 1');

    $opened = $page->script('window.fwin().__opened[0]');
    expect($opened)->toStartWith('/tantra/connect-window?');
    parse_str((string) parse_url($opened, PHP_URL_QUERY), $query);
    expect($query['to'] ?? '')->toStartWith('/connect/linkedin')
        ->and($query['nonce'] ?? '')->toMatch('/^[A-Za-z0-9_-]{16,64}$/');

    return $query['nonce'];
}

function broadcastConnectReport(mixed $page, array $report): void
{
    $json = json_encode(['v' => 1, ...$report]);
    $page->script("(() => { const c = new BroadcastChannel('tantra.connect'); c.postMessage({$json}); c.close(); return true; })()");
}

function pauseInPage(mixed $page, int $milliseconds): void
{
    $page->script("new Promise((resolve) => setTimeout(() => resolve(true), {$milliseconds}))");
}

beforeEach(function () {
    Cache::flush();
    config([
        'tantra.embed.enabled' => true,
        'tantra.clerk.enabled' => false,
        'tantra.clerk.frontend_api' => BROWSER_EMBED_ISSUER,
        'tantra.clerk.authorized_parties' => [BROWSER_EMBED_CRM],
        'tantra.clerk.secret_key' => null,
    ]);
    Http::fake([
        BROWSER_EMBED_ISSUER.'/.well-known/jwks.json' => Http::response(browserEmbedKey()['jwks']),
    ]);
    serveEmbedParents();
});

describe('layout', function () {
    it('keeps a standalone page exactly as it was', function () {
        [$user] = browserEmbedAdmin();
        $this->actingAs($user);

        $page = visit('/settings/workspace/channels');
        waitFor($page, '@channels-connect');

        $page->assertPresent('@settings-back')
            ->assertPresent('@settings-sidebar')
            ->assertNoJavaScriptErrors();
    });

    it('drops only the Engine\'s own chrome inside the frame', function () {
        browserEmbedAdmin();

        $page = openEmbedFrame();
        waitForCondition($page, "window.frameHas('[data-testid=\"channels-connect\"]')");

        expect($page->script("window.frameHas('[data-testid=\"settings-back\"]')"))->toBeFalse()
            ->and($page->script("window.frameHas('[data-testid=\"settings-sidebar\"]')"))->toBeTrue()
            ->and($page->script("window.frameHas('[data-testid=\"channels-connect\"]')"))->toBeTrue();

        waitForCondition($page, "window.got('tantra:navigate').some((m) => m.path === '/settings/workspace/channels')");
        expect($page->script("window.got('tantra:navigate').some((m) => m.path === '/settings/workspace/channels' && typeof m.title === 'string')"))->toBeTrue();
    });
});

describe('tantra:go', function () {
    it('follows the parent and ignores any other window, origin or path', function () {
        browserEmbedAdmin();
        $page = openEmbedFrame();

        // Another window with another origin (a sandboxed sibling frame).
        $page->script(<<<'JS'
            (() => {
                const sibling = document.createElement('iframe');
                sibling.setAttribute('sandbox', 'allow-scripts');
                sibling.srcdoc = "<script>parent.frames[0].postMessage({ v: 1, type: 'tantra:go', path: '/settings/workspace' }, '*');<\/script>";
                document.body.appendChild(sibling);
                return true;
            })()
        JS);
        // The parent, with paths off this site and a wrong version.
        $page->script("window.go('//evil.example.com/x'); window.go('https://evil.example.com/x'); window.go('/\\\\evil.example.com'); window.go('/settings/workspace', { v: 2 }); true;");
        pauseInPage($page, 2000);

        expect($page->script('window.framePath()'))->toBe('/settings/workspace/channels');

        $page->script("window.go('/settings/workspace'); true;");
        waitForCondition($page, "window.framePath() === '/settings/workspace'");

        expect($page->script('window.framePath()'))->toBe('/settings/workspace');
        waitForCondition($page, "window.got('tantra:navigate').some((m) => m.path === '/settings/workspace')");
        expect($page->script("window.got('tantra:navigate').some((m) => m.path === '/settings/workspace')"))->toBeTrue();
    });

    it('asks the parent to sign in again once the Engine session is gone', function () {
        browserEmbedAdmin();
        $page = openEmbedFrame();

        $page->script(<<<'JS'
            (async () => {
                const xsrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) || [])[1] || '');
                await fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
                return true;
            })()
        JS);
        $page->script("window.go('/settings/workspace'); true;");
        waitForCondition($page, "window.got('tantra:reauth').length > 0");

        expect($page->script("window.got('tantra:reauth').length"))->toBe(1);
    });
});

describe('connect popup, frame side', function () {
    it('opens the bootstrap page and lands on Channels with every new channel', function () {
        [, , $first, $second] = browserEmbedAdmin();
        $page = openEmbedFrame();
        $nonce = startFrameConnect($page);

        // Another popup's report (another nonce) changes nothing.
        broadcastConnectReport($page, ['type' => 'tantra:connected', 'nonce' => str_repeat('x', 32), 'account' => $first->id, 'created' => true, 'channels' => [$first->id]]);
        pauseInPage($page, 1500);
        expect($page->script("window.frameText().includes('Channel connected.')"))->toBeFalse();

        broadcastConnectReport($page, ['type' => 'tantra:connected', 'nonce' => $nonce, 'account' => $first->id, 'created' => true, 'channels' => [$first->id, $second->id]]);
        waitForCondition($page, "window.frameText().includes('Channel connected.')");

        expect($page->script("window.frameText().includes('Channel connected.')"))->toBeTrue();
        // The posting times step opens, and the address is clean again.
        waitForCondition($page, "window.frameHas('[data-testid=\"connect-channel-dialog\"]') && window.framePath() === '/settings/workspace/channels'");
        expect($page->script("window.frameHas('[data-testid=\"connect-channel-dialog\"]')"))->toBeTrue()
            ->and($page->script('window.framePath()'))->toBe('/settings/workspace/channels');
    });

    it('takes the report from a storage event too', function () {
        [, , $first] = browserEmbedAdmin();
        $page = openEmbedFrame();
        $nonce = startFrameConnect($page);

        $json = json_encode(['v' => 1, 'type' => 'tantra:connected', 'nonce' => $nonce, 'account' => $first->id, 'created' => false, 'channels' => []]);
        $page->script("localStorage.setItem('tantra.connect', JSON.stringify({$json})); localStorage.removeItem('tantra.connect'); true;");
        waitForCondition($page, "window.frameText().includes('Channel connected.')");

        expect($page->script("window.frameText().includes('Channel connected.')"))->toBeTrue();
    });

    it('says so when the person leaves the flow in the popup', function () {
        browserEmbedAdmin();
        $page = openEmbedFrame();
        $nonce = startFrameConnect($page);

        broadcastConnectReport($page, ['type' => 'tantra:connect-left', 'nonce' => $nonce]);
        waitForCondition($page, "window.frameText().includes('The sign in window closed before a channel was connected.')");

        expect($page->script("window.frameText().includes('The sign in window closed before a channel was connected.')"))->toBeTrue();
    });

    it('treats a closed popup as closed once the frame has the person back', function () {
        browserEmbedAdmin();
        $page = openEmbedFrame();
        startFrameConnect($page);

        $page->script('window.fwin().document.hasFocus = () => true; window.fwin().__popup.closed = true; true;');
        waitForCondition($page, "window.frameText().includes('No channel connected yet.')");

        expect($page->script("window.frameText().includes('No channel connected yet.')"))->toBeTrue();
    });

    it('does not cancel a popup that COOP only severed, and still takes its late report', function () {
        [, , $first] = browserEmbedAdmin();
        $page = openEmbedFrame();
        $nonce = startFrameConnect($page);

        // Severed: popup.closed reads true while the person is still in it.
        $page->script('window.fwin().document.hasFocus = () => false; window.fwin().__popup.closed = true; true;');
        pauseInPage($page, 2500);
        expect($page->script("window.frameText().includes('No channel connected yet.')"))->toBeFalse();

        // The person comes back to the frame before finishing.
        $page->script("window.fwin().dispatchEvent(new Event('pointerdown')); true;");
        waitForCondition($page, "window.frameText().includes('No channel connected yet.')");
        expect($page->script("window.frameText().includes('No channel connected yet.')"))->toBeTrue();

        // The severed popup reports later: still taken.
        broadcastConnectReport($page, ['type' => 'tantra:connected', 'nonce' => $nonce, 'account' => $first->id, 'created' => false, 'channels' => []]);
        waitForCondition($page, "window.frameText().includes('Channel connected.')");
        expect($page->script("window.frameText().includes('Channel connected.')"))->toBeTrue();
    });
});

describe('connect popup, popup side', function () {
    it('reports on the channel and to its opener, then closes', function () {
        [$user, , $first] = browserEmbedAdmin();
        $this->actingAs($user);
        $nonce = str_repeat('n', 32);

        $page = visit('/__tantra-opener?'.http_build_query(['url' => "/tantra/connect-window?nonce={$nonce}&to=/channels/{$first->id}/publish"]));
        $page->click('#open');
        waitForCondition($page, "window.reports.some((r) => r.via === 'channel') && window.reports.some((r) => r.via === 'opener')");

        $report = $page->script("window.reports.find((r) => r.via === 'channel')");
        expect($report)->toMatchArray(['type' => 'tantra:connected', 'nonce' => $nonce, 'account' => $first->id, 'created' => false]);
        waitForCondition($page, 'window.popup && window.popup.closed');
        expect($page->script('window.popup.closed'))->toBeTrue();
    });

    it('reports without any opener, as a COOP severed popup has none', function () {
        [$user, , $first] = browserEmbedAdmin();
        $this->actingAs($user);
        $nonce = str_repeat('s', 32);

        $page = visit('/__tantra-opener?'.http_build_query([
            'url' => "/tantra/connect-window?nonce={$nonce}&to=/channels/{$first->id}/publish",
            'features' => 'noopener',
        ]));
        $page->click('#open');
        waitForCondition($page, "window.reports.some((r) => r.via === 'channel')");

        expect($page->script("window.reports.find((r) => r.via === 'channel')"))->toMatchArray(['type' => 'tantra:connected', 'nonce' => $nonce, 'account' => $first->id])
            ->and($page->script("window.reports.some((r) => r.via === 'opener')"))->toBeFalse();
    });

    it('says the person can close it when it cannot close itself', function (string $to, string $text) {
        [$user, , $first] = browserEmbedAdmin();
        $this->actingAs($user);
        $to = str_replace('{channel}', $first->id, $to);

        $page = visit('/tantra/connect-window?'.http_build_query(['nonce' => str_repeat('c', 32), 'to' => $to]));
        waitFor($page, '@connect-window-done');

        $page->assertSeeIn('@connect-window-done', $text);
        expect($page->script("sessionStorage.getItem('tantra.connect')"))->toBeNull()
            ->and($page->script('window.name'))->toBe('');
    })->with([
        'connected' => ['/channels/{channel}/publish', 'Connected. You can close this window.'],
        'left the flow' => ['/settings/workspace', 'You can close this window.'],
    ]);
});

describe('Channels params', function () {
    it('opens the connect request another page sent with ?connect=', function () {
        browserEmbedAdmin();
        $page = openEmbedFrame('/settings/workspace/channels?connect=1&network=linkedin');

        waitForCondition($page, "window.frameHas('[data-testid=\"connect-panel-continue\"]')");
        expect($page->script("window.frameHas('[data-testid=\"connect-panel-continue\"]')"))->toBeTrue();
        waitForCondition($page, "window.framePath() === '/settings/workspace/channels'");
        expect($page->script('window.framePath()'))->toBe('/settings/workspace/channels');
    });

    it('confirms ?connected= only for a channel of this workspace', function () {
        [, , $first] = browserEmbedAdmin();

        $page = openEmbedFrame('/settings/workspace/channels?connected=00000000-0000-4000-8000-000000000000');
        pauseInPage($page, 1500);
        expect($page->script("window.frameText().includes('Channel connected.')"))->toBeFalse();

        $page = openEmbedFrame("/settings/workspace/channels?connected={$first->id}");
        waitForCondition($page, "window.frameText().includes('Channel connected.')");
        expect($page->script("window.frameText().includes('Channel connected.')"))->toBeTrue();
    });
});
