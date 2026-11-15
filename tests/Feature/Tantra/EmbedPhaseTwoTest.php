<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Embed\Embed;
use App\Tantra\Embed\Provision;
use App\Tantra\Notify\SendToCrm;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// tantra: Content phase 2 inside Tantra (One Tantra S-12): every teammate gets in, email links open in Tantra,
// and signing out of Tantra ends the Engine session.

const S12_ISSUER = 'https://clerk.usetantra.com';
const S12_KID = 'ins_s12_test_key';
const S12_CRM = 'https://crm.usetantra.com';

/** @return array{private: string, jwks: array<string, mixed>} */
function s12ClerkKey(): array
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
        'jwks' => ['keys' => [['use' => 'sig', 'kty' => 'RSA', 'kid' => S12_KID, 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]],
    ];
}

/** A Tantra session token with a verified email (as Clerk's Backend API would confirm it). */
function s12Token(string $sub, string $email): string
{
    return JWT::encode([
        'iss' => S12_ISSUER, 'sub' => $sub, 'azp' => S12_CRM, 'sid' => 'sess_'.$sub,
        'iat' => time() - 10, 'nbf' => time() - 10, 'exp' => time() + 60,
        'email_verified' => true, 'email' => $email,
    ], s12ClerkKey()['private'], 'RS256', S12_KID);
}

/** The Tantra workspace the Engine's CRM key belongs to, with these members. */
function s12Team(array $emails, int $status = 200): void
{
    Http::fake([
        S12_ISSUER.'/.well-known/jwks.json' => Http::response(s12ClerkKey()['jwks']),
        'crm.test/api/me' => Http::response(['members' => array_map(fn (string $e): array => ['email' => $e, 'name' => null, 'role' => 'member'], $emails)], $status),
    ]);
}

beforeEach(function () {
    Cache::flush();
    $this->owner = User::factory()->create(['email' => 'adi@usetantra.com']);
    // The owner's Account is the first one created: the main Account (the Tantra team).
    $this->account = Account::query()->whereKey($this->owner->account_id)->sole();
    $this->team = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    config([
        'tantra.embed.enabled' => true,
        'tantra.embed.parents' => [S12_CRM],
        'tantra.embed.provision.enabled' => true,
        'tantra.embed.provision.workspace' => 'Tantra',
        'tantra.crm.url' => 'https://crm.test',
        'tantra.crm.api_key' => 'ck_test',
        'tantra.clerk.enabled' => false,
        'tantra.clerk.frontend_api' => S12_ISSUER,
        'tantra.clerk.authorized_parties' => [S12_CRM],
        'tantra.clerk.secret_key' => null,
        'tantra.clerk.team_emails' => ['team@growthclubpro.com'],
    ]);
});

describe('auto provisioning', function () {
    it('creates a login for a Tantra teammate and adds them to the team workspace', function () {
        s12Team(['adi@usetantra.com', 'Priya.Raman@usetantra.com']);

        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_priya', 'priya.raman@usetantra.com')])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'email' => 'priya.raman@usetantra.com']);

        $priya = User::query()->where('email', 'priya.raman@usetantra.com')->sole();
        $this->assertAuthenticatedAs($priya);
        expect($priya->clerk_user_id)->toBe('user_priya')
            ->and($priya->account_id)->toBe($this->account->id)
            ->and($priya->current_workspace_id)->toBe($this->team->id)
            ->and($priya->name)->toBe('Priya Raman')
            ->and($this->team->hasMember($priya))->toBeTrue();
        $pivot = $this->team->members()->where('users.id', $priya->id)->first()->pivot;
        expect((bool) $pivot->is_admin)->toBeFalse()->and((bool) $pivot->requires_approval)->toBeTrue();
        Http::assertSent(fn ($r) => $r->url() === 'https://crm.test/api/me' && $r->hasHeader('Authorization', 'Bearer ck_test'));
    });

    it('signs the same teammate in again without a second login', function () {
        s12Team(['priya@usetantra.com']);
        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_priya', 'priya@usetantra.com')])->assertOk();
        auth()->guard('web')->logout();
        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_priya', 'priya@usetantra.com')])->assertOk();

        expect(User::query()->where('email', 'priya@usetantra.com')->count())->toBe(1)
            ->and($this->team->members()->where('users.id', User::query()->where('email', 'priya@usetantra.com')->value('id'))->count())->toBe(1);
    });

    it('answers no access for someone who is not in the Tantra team', function () {
        s12Team(['adi@usetantra.com']);

        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_x', 'stranger@example.com')])
            ->assertForbidden()->assertExactJson(['code' => 'no_access']);

        $this->assertGuest();
        expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
    });

    it('creates nothing while the flag is off', function () {
        config(['tantra.embed.provision.enabled' => false]);
        s12Team(['priya@usetantra.com']);

        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_priya', 'priya@usetantra.com')])->assertForbidden();

        expect(User::query()->where('email', 'priya@usetantra.com')->exists())->toBeFalse();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/me'));
    });

    it('creates nothing when Tantra cannot say who is in the team', function () {
        s12Team([], 503);

        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_priya', 'priya@usetantra.com')])->assertForbidden();

        expect(User::query()->where('email', 'priya@usetantra.com')->exists())->toBeFalse();
    });

    it('never adds an Engine user of another Account to the team: they sign in as themselves', function () {
        s12Team(['priya@usetantra.com']);
        // The factory gives this user an Account of their own, created after the main one.
        $other = User::factory()->create(['email' => 'priya@usetantra.com']);
        expect($other->account_id)->not->toBe($this->account->id);

        $this->postJson('/tantra/embed/session', ['token' => s12Token('user_priya', 'priya@usetantra.com')])->assertOk();

        $this->assertAuthenticatedAs($other);
        expect($this->team->hasMember($other))->toBeFalse()
            ->and(User::query()->where('email', 'priya@usetantra.com')->count())->toBe(1)
            ->and(Provision::forTeammate('priya@usetantra.com', 'user_someone_else'))->toBeNull();
    });

    it('creates nothing when the team workspace is missing', function () {
        config(['tantra.embed.provision.workspace' => 'No such workspace']);
        s12Team(['priya@usetantra.com']);

        expect(Provision::forTeammate('priya@usetantra.com', 'user_priya'))->toBeNull();
        expect(User::query()->where('email', 'priya@usetantra.com')->exists())->toBeFalse();
    });

    it('reads the team once per 5 minutes', function () {
        s12Team(['priya@usetantra.com']);
        Provision::teamEmails();
        Provision::teamEmails();

        Http::assertSentCount(1);
    });
});

describe('email links into Tantra', function () {
    beforeEach(function () {
        config(['app.url' => 'https://content.usetantra.com', 'tantra.embed.email_links' => true, 'tantra.embed.shell_url' => 'https://crm.usetantra.com/content']);
    });

    it('opens an Engine page inside Tantra', function () {
        expect(Embed::shellLink('https://content.usetantra.com/tantra/review?item=12#top'))
            ->toBe('https://crm.usetantra.com/content?p='.rawurlencode('/tantra/review?item=12').'#top')
            ->and(Embed::shellLink('https://content.usetantra.com/schedule'))->toBe('https://crm.usetantra.com/content?p=%2Fschedule');
    });

    it('leaves sign in, OAuth, signed, preview and other sites\' links alone', function (string $url) {
        expect(Embed::shellLink($url))->toBe($url);
    })->with([
        'https://content.usetantra.com/connect/linkedin',
        'https://content.usetantra.com/accounts/linkedin/callback?code=1',
        'https://content.usetantra.com/login',
        'https://content.usetantra.com/tantra/join/5?expires=1&signature=abc',
        'https://content.usetantra.com/settings/workspace?signature=abc',
        'https://content.usetantra.com/previewlink/x',
        'https://content.usetantra.com/',
        'https://evil.example.com/tantra/review',
        'https://content.usetantra.com:8443/tantra/review',
        'mailto:team@growthclubpro.com',
    ]);

    it('changes nothing while the flag is off', function () {
        config(['tantra.embed.email_links' => false]);

        expect(Embed::shellLink('https://content.usetantra.com/tantra/review'))->toBe('https://content.usetantra.com/tantra/review')
            ->and(Embed::shellLinks('<a href="https://content.usetantra.com/tantra/review">Open</a>'))->toBe('<a href="https://content.usetantra.com/tantra/review">Open</a>');
    });

    it('rewrites every href in an email and nothing else', function () {
        $html = '<p>Hi</p><a href="https://content.usetantra.com/tantra/review?a=1&amp;b=2">Review</a> <a href=\'https://content.usetantra.com/connect/x\'>Connect</a>';

        expect(Embed::shellLinks($html))->toBe('<p>Hi</p><a href="https://crm.usetantra.com/content?p='.rawurlencode('/tantra/review?a=1&b=2').'">Review</a> <a href=\'https://content.usetantra.com/connect/x\'>Connect</a>');
    });

    it('sends the call to action into Tantra in the CRM event', function () {
        $user = User::factory()->create();
        $job = new SendToCrm('id-1', 'engine.review_ready', $user->id, [], null, 'Tantra', 'https://content.usetantra.com/tantra/review', 'Review');

        expect($job->payload($user)['message']['cta_url'])->toBe('https://crm.usetantra.com/content?p=%2Ftantra%2Freview');
    });
});

describe('sign out from Tantra', function () {
    it('serves the sign out page only with the embed on', function () {
        $this->get('/tantra/embed/signout')->assertOk()->assertSee('tantra:signed-out', false)->assertSee('crm.usetantra.com', false);
        config(['tantra.embed.enabled' => false]);
        $this->get('/tantra/embed/signout')->assertNotFound();
    });

    it('ends the Engine session', function () {
        $this->actingAs($this->owner)->postJson('/tantra/embed/signout')->assertOk()->assertExactJson(['ok' => true]);

        $this->assertGuest();
    });

    it('answers ok when nobody is signed in', function () {
        $this->postJson('/tantra/embed/signout')->assertOk();
        $this->assertGuest();
    });
});
