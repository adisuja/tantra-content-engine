<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Embed\Embed;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// tantra: the Engine inside the Tantra CRM's Content tab (PRD 2026-11-15, V2).

const EMBED_ISSUER = 'https://clerk.usetantra.com';
const EMBED_KID = 'ins_embed_test_key';
const EMBED_CRM = 'https://crm.usetantra.com';

/**
 * @return array{private: string, jwks: array<string, mixed>}
 */
function embedClerkKey(): array
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
            'use' => 'sig', 'kty' => 'RSA', 'kid' => EMBED_KID, 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]],
    ];
}

/**
 * A CRM session token: azp is the CRM, no email claim (the CRM's default token).
 *
 * @param  array<string, mixed>  $claims
 */
function embedToken(string $sub, array $claims = []): string
{
    return JWT::encode([
        'iss' => EMBED_ISSUER,
        'sub' => $sub,
        'azp' => EMBED_CRM,
        'sid' => 'sess_'.$sub,
        'iat' => time() - 10,
        'nbf' => time() - 10,
        'exp' => time() + 60,
        ...$claims,
    ], embedClerkKey()['private'], 'RS256', EMBED_KID);
}

function embedUser(string $email, string $clerkId): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->forceFill(['clerk_user_id' => $clerkId])->save();

    return $user->refresh();
}

beforeEach(function () {
    Cache::flush();
    config([
        'tantra.embed.enabled' => true,
        'tantra.embed.parents' => [EMBED_CRM, 'http://localhost:5173'],
        // The standalone Clerk sign in stays off, as in production.
        'tantra.clerk.enabled' => false,
        'tantra.clerk.frontend_api' => EMBED_ISSUER,
        'tantra.clerk.authorized_parties' => ['https://content.usetantra.com', EMBED_CRM],
        'tantra.clerk.secret_key' => null,
        'tantra.clerk.team_emails' => ['team@example.com'],
    ]);
    Http::fake([
        EMBED_ISSUER.'/.well-known/jwks.json' => Http::response(embedClerkKey()['jwks']),
    ]);
});

describe('embed session', function () {
    it('signs in the CRM user with a valid token', function () {
        $priya = embedUser('priya@usetantra.com', 'user_priya');

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'email' => 'priya@usetantra.com']);

        $this->assertAuthenticatedAs($priya);
    });

    it('signs in by verified email when the token carries it', function () {
        $priya = User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya', ['email_verified' => true, 'email' => 'priya@usetantra.com'])])
            ->assertOk();

        $this->assertAuthenticatedAs($priya);
        expect($priya->refresh()->clerk_user_id)->toBe('user_priya');
    });

    it('refuses a token made for another site', function () {
        embedUser('priya@usetantra.com', 'user_priya');

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya', ['azp' => 'https://evil.example.com'])])
            ->assertStatus(401)
            ->assertExactJson(['code' => 'token_invalid']);

        $this->assertGuest();
    });

    it('refuses an expired token', function () {
        embedUser('priya@usetantra.com', 'user_priya');

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya', ['exp' => time() - 120, 'iat' => time() - 200, 'nbf' => time() - 200])])
            ->assertStatus(401)
            ->assertExactJson(['code' => 'token_invalid']);

        $this->assertGuest();
    });

    it('refuses a missing or junk token', function () {
        $this->postJson('/tantra/embed/session', [])->assertStatus(401)->assertExactJson(['code' => 'token_invalid']);
        $this->postJson('/tantra/embed/session', ['token' => 'not-a-token'])->assertStatus(401);

        $this->assertGuest();
    });

    it('answers no access for a CRM user with no Engine login', function () {
        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_stranger', ['email_verified' => true, 'email' => 'stranger@example.com'])])
            ->assertForbidden()
            ->assertExactJson(['code' => 'no_access']);

        $this->assertGuest();
        expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
    });

    it('keeps the session when the same person opens Content again', function () {
        $priya = embedUser('priya@usetantra.com', 'user_priya');

        $this->actingAs($priya)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertOk();

        $this->assertAuthenticatedAs($priya);
    });

    it('swaps to the new CRM user when another person was signed in', function () {
        $adi = embedUser('owner@example.com', 'user_adi');
        $priya = embedUser('priya@usetantra.com', 'user_priya');

        $this->actingAs($adi)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertOk()
            ->assertJson(['email' => 'priya@usetantra.com']);

        $this->assertAuthenticatedAs($priya);
    });

    it('swaps an Account owner\'s session for the team login when the team login opens Content', function () {
        config(['tantra.clerk.team_emails' => ['team@example.test']]);
        $owner = createApiTestToken()['user'];
        $owner->forceFill(['clerk_user_id' => 'user_owner'])->save();

        $this->actingAs($owner)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_team', ['email_verified' => true, 'email' => 'team@example.test'])])
            ->assertOk()
            ->assertJson(['email' => 'team@example.test']);

        $team = User::query()->where('email', 'team@example.test')->sole();
        $this->assertAuthenticatedAs($team);
        expect($team->id)->not->toBe($owner->id)
            ->and($team->clerk_user_id)->toBe('user_team');

        // Opening Content again as the owner swaps back: the CRM member decides, never the old engine cookie.
        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_owner')])->assertOk();
        $this->assertAuthenticatedAs($owner);
    });

    it('ends the previous session before answering no access', function () {
        $adi = embedUser('owner@example.com', 'user_adi');

        $this->actingAs($adi)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_stranger', ['email_verified' => true, 'email' => 'stranger@example.com'])])
            ->assertForbidden()
            ->assertExactJson(['code' => 'no_access']);

        $this->assertGuest();
    });

    it('forgets the previous person\'s remember cookie before answering no access', function () {
        $adi = embedUser('owner@example.com', 'user_adi');
        $recaller = Auth::guard('web')->getRecallerName();

        $this->actingAs($adi)
            ->withCookie($recaller, $adi->id.'|adi-remember|'.$adi->password)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_stranger', ['email_verified' => true, 'email' => 'stranger@example.com'])])
            ->assertForbidden()
            ->assertCookieExpired($recaller);

        $this->assertGuest();
    });

    it('starts a fresh session when it swaps, and keeps the session for the same person', function () {
        $adi = embedUser('owner@example.com', 'user_adi');
        $priya = embedUser('priya@usetantra.com', 'user_priya');

        $this->actingAs($adi)
            ->withSession(['tantra_marker' => 'adi'])
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_adi')])
            ->assertOk();
        expect(session('tantra_marker'))->toBe('adi');
        $before = app('session')->driver()->getId();

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])->assertOk();

        $this->assertAuthenticatedAs($priya);
        expect(session('tantra_marker'))->toBeNull()
            ->and(app('session')->driver()->getId())->not->toBe($before);
    });

    it('answers 503 server, not a bad token, when Clerk\'s signing keys are down', function () {
        embedUser('priya@usetantra.com', 'user_priya');
        // Another Frontend API, whose key set is down (the default fake stays for the real one).
        config(['tantra.clerk.frontend_api' => 'https://clerk-down.example.com']);
        Http::fake(['https://clerk-down.example.com/.well-known/jwks.json' => Http::response('down', 503)]);

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertStatus(503)
            ->assertExactJson(['code' => 'server']);

        $this->assertGuest();
    });

    it('answers 503 server, not no access, when Clerk\'s Backend API fails', function (mixed $answer) {
        config(['tantra.clerk.secret_key' => 'sk_test_x']);
        User::factory()->create(['email' => 'priya@usetantra.com']);
        Http::fake([
            EMBED_ISSUER.'/.well-known/jwks.json' => Http::response(embedClerkKey()['jwks']),
            'https://api.clerk.com/v1/users/*' => $answer,
        ]);

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertStatus(503)
            ->assertExactJson(['code' => 'server']);

        $this->assertGuest();
    })->with([
        'server error' => fn () => Http::response('down', 500),
        'rate limited' => fn () => Http::response('slow down', 429),
        'wrong secret key' => fn () => Http::response('unauthorized', 401),
        'timeout' => fn () => Http::failedConnection('timed out'),
    ]);

    it('still signs in a linked person while Clerk\'s Backend API fails', function () {
        config(['tantra.clerk.secret_key' => 'sk_test_x']);
        $priya = embedUser('priya@usetantra.com', 'user_priya');
        Http::fake([
            EMBED_ISSUER.'/.well-known/jwks.json' => Http::response(embedClerkKey()['jwks']),
            'https://api.clerk.com/v1/users/*' => Http::response('down', 500),
        ]);

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])->assertOk();

        $this->assertAuthenticatedAs($priya);
    });

    it('answers 503 server, not no access, when no CLERK_SECRET_KEY is set to read the email', function () {
        User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertStatus(503)
            ->assertExactJson(['code' => 'server']);

        $this->assertGuest();
    });

    it('answers no access when Clerk says the person has no verified email', function () {
        config(['tantra.clerk.secret_key' => 'sk_test_x']);
        Http::fake([
            EMBED_ISSUER.'/.well-known/jwks.json' => Http::response(embedClerkKey()['jwks']),
            'https://api.clerk.com/v1/users/*' => Http::response([
                'primary_email_address_id' => 'idn_1',
                'email_addresses' => [['id' => 'idn_1', 'email_address' => 'priya@usetantra.com', 'verification' => ['status' => 'unverified']]],
            ]),
        ]);

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertForbidden()
            ->assertExactJson(['code' => 'no_access']);
    });

    it('does not cycle the previous person\'s remember token', function () {
        $adi = embedUser('owner@example.com', 'user_adi');
        $adi->forceFill(['remember_token' => 'adi-other-devices'])->save();
        embedUser('priya@usetantra.com', 'user_priya');

        $this->actingAs($adi)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])
            ->assertOk();

        expect($adi->refresh()->remember_token)->toBe('adi-other-devices');
    });

    it('changes nothing for a bad token while someone is signed in', function () {
        $adi = embedUser('owner@example.com', 'user_adi');

        $this->actingAs($adi)
            ->postJson('/tantra/embed/session', ['token' => embedToken('user_priya', ['azp' => 'https://evil.example.com'])])
            ->assertStatus(401);

        $this->assertAuthenticatedAs($adi);
    });

    it('is not there while the switch is off', function () {
        config(['tantra.embed.enabled' => false]);
        embedUser('priya@usetantra.com', 'user_priya');

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])->assertNotFound();
        $this->get('/tantra/embed?to=/tantra/review')->assertNotFound();

        $this->assertGuest();
    });

    it('leaves the standalone Clerk sign in off', function () {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
        $this->post('/auth/clerk/session', ['token' => embedToken('user_priya')])->assertNotFound();
    });
});

describe('embed boot page', function () {
    it('renders for a guest with the parents and the page to open', function () {
        $response = $this->get('/tantra/embed?to='.urlencode('/schedule?tab=approvals'))->assertOk();

        $html = $response->getContent();
        expect($html)->toContain("var to = '\/schedule?tab=approvals'")
            ->and($html)->toContain('crm.usetantra.com')
            ->and($html)->toContain('localhost:5173')
            ->and($html)->toContain('tantra:ready')
            ->and($html)->toContain('\/tantra\/embed\/session')
            ->and($html)->not->toContain('—')
            ->and($html)->not->toContain('–');
    });

    it('falls back to Insights for any path off this site', function (string $to) {
        $html = $this->get('/tantra/embed?to='.urlencode($to))->assertOk()->getContent();

        expect($html)->toContain("var to = '\/tantra\/insights'");
    })->with([
        'protocol relative' => '//evil.com',
        'absolute' => 'https://evil.com',
        'backslash' => '/\\evil.com',
        'no slash' => 'evil.com',
        'tab' => "/\t/evil.com",
        'newline' => "/\n/evil.com",
        'too long' => '/'.str_repeat('a', 2000),
        'empty' => '',
    ]);

    it('checks paths the same way in code', function () {
        expect(Embed::safePath('/tantra/review'))->toBe('/tantra/review')
            ->and(Embed::safePath('/schedule?tab=approvals'))->toBe('/schedule?tab=approvals')
            ->and(Embed::safePath('/connect/x/prepare?next=https://example.com'))->toBe('/connect/x/prepare?next=https://example.com')
            ->and(Embed::safePath('/x?u=javascript://alert(1)'))->toBe('/x?u=javascript://alert(1)')
            ->and(Embed::safePath('/calendar/2026-11-15T10:00'))->toBe('/calendar/2026-11-15T10:00')
            ->and(Embed::safePath('/a:80'))->toBe('/a:80')
            ->and(Embed::safePath(['/x']))->toBe('/tantra/insights')
            ->and(Embed::safePath(null))->toBe('/tantra/insights')
            ->and(Embed::safePath('/'.str_repeat('a', 1999)))->toHaveLength(2000);
    });

    it('keeps only well formed parent origins', function () {
        config(['tantra.embed.parents' => ['https://crm.usetantra.com/', 'https://x.com; script-src *', 'javascript:alert(1)', 'HTTP://LOCALHOST:5173']]);

        expect(Embed::parents())->toBe(['https://crm.usetantra.com', 'http://localhost:5173']);
    });
});

describe('connect popup bootstrap page', function () {
    it('marks the popup with the nonce and opens the connect page', function () {
        $html = $this->get('/tantra/connect-window?'.http_build_query(['nonce' => 'abcdefghijklmnopqrstuvwxyz012345', 'to' => '/connect/linkedin/prepare?kind=profile']))
            ->assertOk()
            ->getContent();

        expect($html)->toContain("var nonce = 'abcdefghijklmnopqrstuvwxyz012345'")
            ->and($html)->toContain("var to = '\/connect\/linkedin\/prepare?kind=profile'")
            ->and($html)->toContain("sessionStorage.setItem('tantra.connect', nonce)")
            ->and($html)->toContain("window.name = 'tantra-connect:' + nonce")
            ->and($html)->toContain('location.replace(to)')
            ->and($html)->not->toContain('opener')
            ->and($html)->not->toContain('—')
            ->and($html)->not->toContain('–');
    });

    it('never leaves this site', function () {
        $html = $this->get('/tantra/connect-window?'.http_build_query(['nonce' => 'abcdefghijklmnopqrstuvwxyz012345', 'to' => '//evil.com/x']))->assertOk()->getContent();

        expect($html)->toContain("var to = '\/settings\/workspace\/channels'");
    });

    it('refuses a missing or malformed nonce, and is not there while the switch is off', function () {
        $this->get('/tantra/connect-window?to=/x')->assertNotFound();
        $this->get('/tantra/connect-window?nonce=short&to=/x')->assertNotFound();
        $this->get('/tantra/connect-window?'.http_build_query(['nonce' => "abcdefghijklmnop'</script>", 'to' => '/x']))->assertNotFound();

        config(['tantra.embed.enabled' => false]);
        $this->get('/tantra/connect-window?nonce=abcdefghijklmnopqrstuvwxyz012345&to=/x')->assertNotFound();
    });
});

describe('frame-ancestors header', function () {
    it('lets only the Engine and the CRM frame its pages', function () {
        $this->get('/login')->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://crm.usetantra.com http://localhost:5173");
    });

    it('allows only the Engine itself when no parent is set', function () {
        config(['tantra.embed.parents' => []]);

        $this->get('/login')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    });

    it('sends the header on signed in Inertia pages and the boot page', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/profile')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://crm.usetantra.com http://localhost:5173");
        $this->get('/tantra/embed')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://crm.usetantra.com http://localhost:5173");
    });

    it('never overwrites a response\'s own policy', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
        $workspace->members()->attach($user->id, membershipPivot('admin'));
        $user->update(['current_workspace_id' => $workspace->id]);
        subscribeAccount($user->fresh()->account);
        Http::fake([
            EMBED_ISSUER.'/.well-known/jwks.json' => Http::response(embedClerkKey()['jwks']),
            '*' => Http::response(str_repeat('A', 200), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($user->fresh())->get(route('app.favicon', 'example.com'))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "script-src 'none'");
    });

    it('shares the parents with the pages only while the switch is on', function () {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('tantraEmbedParents', [EMBED_CRM, 'http://localhost:5173']));

        config(['tantra.embed.enabled' => false]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('tantraEmbedParents', []));
    });
});

describe('connect popup contract', function () {
    // The popup (resources/js/lib/tantra/embed.ts) stays open on these pages
    // and only reports tantra:connected from the connected channel's page.
    // Every callback failure lands on accounts/ConnectFinish (failConnection,
    // ConnectSafetyNet), covered in tests/Feature/Social/*ControllerTest.php.
    it('waits on every connect step and outcome page that exists', function () {
        $source = (string) file_get_contents(resource_path('js/lib/tantra/embed.ts'));
        preg_match('/export const CONNECT_STEPS = \[(.*?)\];/s', $source, $match);
        preg_match_all("/'([^']+)'/", $match[1] ?? '', $components);

        expect($components[1])->toBe(['accounts/ConnectFinish', 'tantra/connect/Prepare', 'accounts/MastodonConnect', 'accounts/BlueskyConnect']);
        foreach ($components[1] as $component) {
            expect(resource_path("js/pages/{$component}.vue"))->toBeFile();
        }
    });

    it('sends every connect failure to the outcome page the popup waits on', function () {
        $source = (string) file_get_contents(app_path('Http/Controllers/Auth/SocialController.php'));

        expect($source)->toContain("Inertia::render('accounts/ConnectFinish'")
            ->and($source)->toMatch("/function failConnection.*?redirect\(\)->route\('app\.social\.connect\.show'/s")
            ->and((string) file_get_contents(app_path('Tantra/Channels/ConnectSafetyNet.php')))->toContain("redirect()->route('app.social.connect.show'");
    });
});

// tantra: AF-10 and AF-04, the engine as Tantra shows it (TantraView).
describe('Tantra view', function () {
    it('marks the embed session and shares it with the pages', function () {
        embedUser('priya@usetantra.com', 'user_priya');

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])->assertOk();

        expect(session(\App\Tantra\Embed\TantraView::SESSION))->toBeTrue();

        $request = \Illuminate\Http\Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(\App\Tantra\Embed\TantraView::SESSION, true);
        $request->session()->put(\App\Tantra\Embed\TantraView::SESSION_TIMEZONE, 'Europe/London');
        expect(\App\Tantra\Embed\TantraView::props($request))->toBe(['active' => true, 'timezone' => 'Europe/London']);
    });

    it('gives the person Tantra\'s time zone sent with the embed session, in place of the engine default', function () {
        $priya = embedUser('priya@usetantra.com', 'user_priya');
        $priya->forceFill(['timezone' => 'Asia/Kolkata'])->save();

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya'), 'timezone' => 'Europe/London'])->assertOk();

        expect($priya->refresh()->timezone)->toBe('Europe/London')
            ->and(session(\App\Tantra\Embed\TantraView::SESSION_TIMEZONE))->toBe('Europe/London');
    });

    it('keeps a time zone the person set themselves and ignores one that is not real', function () {
        $priya = embedUser('priya@usetantra.com', 'user_priya');
        $priya->forceFill(['timezone' => 'America/New_York'])->save();

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya'), 'timezone' => 'Europe/London'])->assertOk();
        expect($priya->refresh()->timezone)->toBe('America/New_York');

        $priya->forceFill(['timezone' => 'UTC'])->save();
        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya'), 'timezone' => 'Mars/Olympus'])->assertOk();
        expect($priya->refresh()->timezone)->toBe('UTC');
    });

    it('uses the workspace time zone provisioning stored when the session sends none', function () {
        $priya = embedUser('priya@usetantra.com', 'user_priya');
        $workspace = Workspace::factory()->create(['user_id' => $priya->id]);
        $priya->forceFill(['current_workspace_id' => $workspace->id, 'timezone' => 'Asia/Kolkata'])->save();
        \App\Tantra\Embed\TantraView::rememberWorkspaceTimezone($workspace, 'Australia/Sydney');

        $this->postJson('/tantra/embed/session', ['token' => embedToken('user_priya')])->assertOk();

        expect($priya->refresh()->timezone)->toBe('Australia/Sydney');
    });

    it('is not active for a session the embed did not start', function () {
        $request = \Illuminate\Http\Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->session()->forget(\App\Tantra\Embed\TantraView::SESSION);

        expect(\App\Tantra\Embed\TantraView::active($request))->toBeFalse()
            ->and(\App\Tantra\Embed\TantraView::props($request))->toBe(['active' => false, 'timezone' => null]);
    });

    it('forwards the time zone from the boot page to the session', function () {
        $html = $this->get('/tantra/embed?to=/tantra/insights')->assertOk()->getContent();

        expect($html)->toContain("typeof data.timezone === 'string'");
    });
});
