<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Auth\ClerkToken;
use App\Tantra\Auth\InvalidClerkToken;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

const CLERK_ISSUER = 'https://clerk.usetantra.com';
const CLERK_KID = 'ins_test_key';

/**
 * A test RSA key and its JWKS, made once per run.
 *
 * @return array{private: string, jwks: array<string, mixed>}
 */
function clerkTestKey(): array
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
            'use' => 'sig', 'kty' => 'RSA', 'kid' => CLERK_KID, 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]],
    ];
}

/**
 * @param  array<string, mixed>  $claims
 */
function clerkToken(array $claims = [], string $kid = CLERK_KID): string
{
    return JWT::encode([
        'iss' => CLERK_ISSUER,
        'sub' => 'user_clerk_1',
        'azp' => 'https://content.usetantra.com',
        'sid' => 'sess_1',
        'iat' => time() - 10,
        'nbf' => time() - 10,
        'exp' => time() + 60,
        ...$claims,
    ], clerkTestKey()['private'], 'RS256', $kid);
}

function clerkWorkspace(User $owner, string $name): Workspace
{
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id, 'name' => $name]);
    $workspace->members()->attach($owner->id, ['is_admin' => true]);

    return $workspace;
}

beforeEach(function () {
    Cache::flush();
    config([
        'tantra.clerk.enabled' => true,
        'tantra.clerk.frontend_api' => CLERK_ISSUER,
        'tantra.clerk.authorized_parties' => ['https://content.usetantra.com'],
        'tantra.clerk.secret_key' => null,
        'tantra.clerk.team_emails' => ['team@growthclubpro.com'],
    ]);
    Http::fake([
        CLERK_ISSUER.'/.well-known/jwks.json' => Http::response(clerkTestKey()['jwks']),
    ]);
});

describe('session token verification', function () {
    it('accepts a valid token', function () {
        $claims = ClerkToken::verify(clerkToken());

        expect($claims['sub'])->toBe('user_clerk_1')
            ->and($claims['azp'])->toBe('https://content.usetantra.com');
    });

    it('refuses an expired token', function () {
        ClerkToken::verify(clerkToken(['exp' => time() - 120, 'iat' => time() - 200, 'nbf' => time() - 200]));
    })->throws(InvalidClerkToken::class, 'did not verify');

    it('refuses a token made for another site', function () {
        ClerkToken::verify(clerkToken(['azp' => 'https://evil.example.com']));
    })->throws(InvalidClerkToken::class, 'another site');

    it('refuses a token with no azp', function () {
        $token = JWT::encode(['iss' => CLERK_ISSUER, 'sub' => 'user_1', 'exp' => time() + 60], clerkTestKey()['private'], 'RS256', CLERK_KID);

        ClerkToken::verify($token);
    })->throws(InvalidClerkToken::class, 'another site');

    it('refuses a token from another Clerk instance', function () {
        ClerkToken::verify(clerkToken(['iss' => 'https://clerk.someone-else.com']));
    })->throws(InvalidClerkToken::class, 'another Clerk instance');

    it('refuses a token signed with another key', function () {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPrivate);
        $token = JWT::encode(['iss' => CLERK_ISSUER, 'sub' => 'user_1', 'azp' => 'https://content.usetantra.com', 'exp' => time() + 60], $otherPrivate, 'RS256', CLERK_KID);

        ClerkToken::verify($token);
    })->throws(InvalidClerkToken::class, 'did not verify');

    it('refuses an unknown key id after reading the key set again', function () {
        ClerkToken::verify(clerkToken(kid: 'ins_unknown'));
    })->throws(InvalidClerkToken::class, 'Unknown signing key');

    it('refuses a token that is not a JWT', function () {
        ClerkToken::verify('not-a-token');
    })->throws(InvalidClerkToken::class);
});

describe('signing in', function () {
    it('shows the Clerk sign in page when the switch is on', function () {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tantra/auth/ClerkSignIn')
            ->where('state', 'sign-in')
            ->where('publishableKey', config('tantra.clerk.publishable_key'))
            ->where('frontendApi', CLERK_ISSUER));
    });

    it('keeps the password form behind other sign in options', function () {
        $this->get('/login?password=1')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    });

    it('keeps the password form when the switch is off', function () {
        config(['tantra.clerk.enabled' => false]);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertNotFound();
    });

    it('signs an existing user in by verified email and links the Clerk user', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email_verified' => true, 'email' => 'Priya@usetantra.com'])])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect($user->refresh()->clerk_user_id)->toBe('user_clerk_1');
    });

    it('signs in by the linked Clerk user on later visits', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);
        $user->forceFill(['clerk_user_id' => 'user_clerk_1'])->save();

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    });

    it('reads the verified primary email from Clerk when the token has none', function () {
        config(['tantra.clerk.secret_key' => 'sk_test_x']);
        Http::fake([
            CLERK_ISSUER.'/.well-known/jwks.json' => Http::response(clerkTestKey()['jwks']),
            'https://api.clerk.com/v1/users/user_clerk_1' => Http::response([
                'id' => 'user_clerk_1',
                'primary_email_address_id' => 'idn_1',
                'email_addresses' => [
                    ['id' => 'idn_0', 'email_address' => 'old@example.com', 'verification' => ['status' => 'verified']],
                    ['id' => 'idn_1', 'email_address' => 'priya@usetantra.com', 'verification' => ['status' => 'verified']],
                ],
            ]),
        ]);
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_x'));
    });

    it('does not trust an unverified primary email', function () {
        config(['tantra.clerk.secret_key' => 'sk_test_x']);
        Http::fake([
            CLERK_ISSUER.'/.well-known/jwks.json' => Http::response(clerkTestKey()['jwks']),
            'https://api.clerk.com/v1/users/*' => Http::response([
                'primary_email_address_id' => 'idn_1',
                'email_addresses' => [['id' => 'idn_1', 'email_address' => 'priya@usetantra.com', 'verification' => ['status' => 'unverified']]],
            ]),
        ]);
        User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect(route('tantra.clerk.no-access'));

        $this->assertGuest();
    });

    it('sends an unknown email to ask for access', function () {
        $this->post('/auth/clerk/session', ['token' => clerkToken(['email_verified' => true, 'email' => 'stranger@example.com'])])
            ->assertRedirect(route('tantra.clerk.no-access'));

        $this->assertGuest();
        expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();

        $this->get(route('tantra.clerk.no-access'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tantra/auth/ClerkSignIn')
            ->where('state', 'no-access')
            ->where('email', 'stranger@example.com'));
    });

    it('does not take over a user linked to another Clerk user', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);
        $user->forceFill(['clerk_user_id' => 'user_someone_else'])->save();

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email_verified' => true, 'email' => 'priya@usetantra.com'])])
            ->assertRedirect(route('tantra.clerk.no-access'));

        $this->assertGuest();
    });

    it('refuses a bad token without signing in', function () {
        User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email_verified' => true, 'email' => 'priya@usetantra.com', 'azp' => 'https://evil.example.com'])])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('clerk');

        $this->assertGuest();
    });

    it('creates the team login on its first sign in with every workspace', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        clerkWorkspace($owner, 'Priya');

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email_verified' => true, 'email' => 'team@growthclubpro.com'])])->assertRedirect();

        $team = User::query()->where('email', 'team@growthclubpro.com')->firstOrFail();
        $this->assertAuthenticatedAs($team);
        expect($team->account_id)->toBe($owner->account_id)
            ->and($team->clerk_user_id)->toBe('user_clerk_1')
            ->and($team->accountWorkspaces()->pluck('name')->sort()->values()->all())->toBe(['Adi', 'Priya']);
    });

    it('signs out of Clerk too when signing out of the Engine', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionHas('tantra_clerk_signed_out', true);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('tantra/auth/ClerkSignIn')->where('signedOut', true));
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('signedOut', false));
    });
});

describe('tantra:team-account', function () {
    it('makes the team login an admin of every workspace, and is safe to run again', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        $priya = clerkWorkspace($owner, 'Priya');

        expect(Artisan::call('tantra:team-account'))->toBe(0);

        $team = User::query()->where('email', 'team@growthclubpro.com')->firstOrFail();
        expect($team->account_id)->toBe($owner->account_id)
            ->and($team->password)->toBeNull()
            ->and($team->isAccountOwner())->toBeFalse()
            ->and($team->current_workspace_id)->not->toBeNull()
            ->and($team->isWorkspaceAdmin($priya))->toBeTrue();

        // A workspace added later, and a membership that lost its admin role.
        $later = clerkWorkspace($owner, 'Tantra');
        DB::table('user_workspace')->where('user_id', $team->id)->where('workspace_id', $priya->id)->update(['is_admin' => false]);

        expect(Artisan::call('tantra:team-account', ['email' => 'TEAM@growthclubpro.com']))->toBe(0);

        expect(User::query()->where('email', 'team@growthclubpro.com')->count())->toBe(1)
            ->and(DB::table('user_workspace')->where('user_id', $team->id)->count())->toBe(3)
            ->and(DB::table('user_workspace')->where('user_id', $team->id)->pluck('is_admin')->map(fn ($v): bool => (bool) $v)->unique()->all())->toBe([true])
            ->and($team->refresh()->isWorkspaceAdmin($later))->toBeTrue()
            ->and($team->accountWorkspaces()->count())->toBe(3);
    });

    it('hands over the Account with --owner', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');

        $other = clerkWorkspace($owner, 'Priya');
        DB::table('user_workspace')->where('user_id', $owner->id)->where('workspace_id', $other->id)->delete();

        expect(Artisan::call('tantra:team-account', ['--owner' => true, '--force' => true, '--account' => 'adi@growthclub.org']))->toBe(0);

        $team = User::query()->where('email', 'team@growthclubpro.com')->firstOrFail();
        expect($team->isAccountOwner())->toBeTrue()
            ->and($owner->refresh()->isAccountOwner())->toBeFalse()
            ->and($owner->isWorkspaceAdmin($other))->toBeTrue()
            ->and(DB::table('user_workspace')->where('user_id', $owner->id)->count())->toBe(2);
    });

    it('asks before handing over the Account', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');

        $this->artisan('tantra:team-account', ['--owner' => true])
            ->expectsConfirmation('Make team@growthclubpro.com the Account owner? The current owner becomes an admin of every workspace.', 'no')
            ->assertExitCode(1);

        expect($owner->refresh()->isAccountOwner())->toBeTrue()
            ->and(User::query()->where('email', 'team@growthclubpro.com')->exists())->toBeFalse();
    });

    it('refuses an email that belongs to another Account', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        User::factory()->create(['email' => 'team@growthclubpro.com']);

        expect(Artisan::call('tantra:team-account', ['--account' => 'adi@growthclub.org']))->toBe(1)
            ->and(DB::table('user_workspace')->where('user_id', User::query()->where('email', 'team@growthclubpro.com')->value('id'))->count())->toBe(0);
    });
});

/**
 * Clerk's Backend API for user_clerk_1 (the production path: no email in the session token).
 *
 * @param  array<string, mixed>  $extra  More faked URLs.
 */
function fakeClerkBackend(string $email, string $status = 'verified', array $extra = []): void
{
    config(['tantra.clerk.secret_key' => 'sk_test_x']);
    Http::fake([
        CLERK_ISSUER.'/.well-known/jwks.json' => Http::response(clerkTestKey()['jwks']),
        'https://api.clerk.com/v1/users/user_clerk_1' => Http::response([
            'id' => 'user_clerk_1',
            'primary_email_address_id' => 'idn_1',
            'email_addresses' => [['id' => 'idn_1', 'email_address' => $email, 'verification' => ['status' => $status]]],
        ]),
        ...$extra,
    ]);
}

describe('review fixes', function () {
    it('refuses a pending session', function () {
        ClerkToken::verify(clerkToken(['sts' => 'pending']));
    })->throws(InvalidClerkToken::class, 'not complete');

    it('answers 422 to a token that is not a string', function () {
        $this->post('/auth/clerk/session', ['token' => ['a', 'b']])->assertStatus(422);
        $this->post('/auth/clerk/session', [])->assertStatus(422);
        $this->assertGuest();
    });

    it('hides every Clerk route while the switch is off', function () {
        config(['tantra.clerk.enabled' => false]);
        $owner = User::factory()->create();

        $this->get(route('tantra.clerk.no-access'))->assertNotFound();
        $this->actingAs($owner)->postJson(route('tantra.team.clerk-unlink', $owner->id))->assertNotFound();
    });

    it('does not trust an email claim without email_verified, and falls back to the Backend API', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'priya@usetantra.com'])])
            ->assertRedirect(route('tantra.clerk.no-access'));
        $this->assertGuest();

        fakeClerkBackend('priya@usetantra.com');
        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'someone@else.com'])])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    });

    it('signs the team login in through the Backend API path production uses', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        fakeClerkBackend('Team@growthclubpro.com');

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect();

        $team = User::query()->where('email', 'team@growthclubpro.com')->firstOrFail();
        $this->assertAuthenticatedAs($team);
        expect($team->clerk_user_id)->toBe('user_clerk_1')->and($team->accountWorkspaces()->count())->toBe(1);
    });

    it('never grants team access from the email stored on the Engine user', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        $adi = clerkWorkspace($owner, 'Adi');
        // Someone changed their own Engine email to the team address.
        $sneaky = User::factory()->create(['email' => 'team@growthclubpro.com', 'account_id' => $owner->account_id]);
        $sneaky->forceFill(['clerk_user_id' => 'user_clerk_1'])->save();
        fakeClerkBackend('sneaky@example.com');

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect();

        $this->assertAuthenticatedAs($sneaky);
        expect($sneaky->refresh()->belongsToWorkspace($adi))->toBeFalse();
    });

    it('refuses the team grant to an Engine user linked to another live Clerk user', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        $team = User::factory()->create(['email' => 'team@growthclubpro.com', 'account_id' => $owner->account_id]);
        $team->forceFill(['clerk_user_id' => 'user_live'])->save();
        fakeClerkBackend('team@growthclubpro.com', extra: ['https://api.clerk.com/v1/users/user_live' => Http::response(['id' => 'user_live'])]);

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertSessionHasErrors('clerk');

        $this->assertGuest();
        expect($team->refresh()->clerk_user_id)->toBe('user_live');
    });

    it('relinks when the linked Clerk user is gone and the new verified email matches', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);
        $user->forceFill(['clerk_user_id' => 'user_deleted'])->save();
        fakeClerkBackend('priya@usetantra.com', extra: ['https://api.clerk.com/v1/users/user_deleted' => Http::response(['errors' => []], 404)]);

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect($user->refresh()->clerk_user_id)->toBe('user_clerk_1');
    });

    it('keeps the link when the linked Clerk user still exists', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);
        $user->forceFill(['clerk_user_id' => 'user_live'])->save();
        fakeClerkBackend('priya@usetantra.com', extra: ['https://api.clerk.com/v1/users/user_live' => Http::response(['id' => 'user_live'])]);

        $this->post('/auth/clerk/session', ['token' => clerkToken()])->assertRedirect(route('tantra.clerk.no-access'));

        $this->assertGuest();
        expect($user->refresh()->clerk_user_id)->toBe('user_live');
    });

    it('unlinks with tantra:clerk-unlink and the owner-only Team page action', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        $priya = User::factory()->create(['email' => 'priya@usetantra.com', 'account_id' => $owner->account_id]);
        $priya->forceFill(['clerk_user_id' => 'user_old'])->save();

        expect(Artisan::call('tantra:clerk-unlink', ['email' => 'PRIYA@usetantra.com']))->toBe(0)
            ->and($priya->refresh()->clerk_user_id)->toBeNull()
            ->and(Artisan::call('tantra:clerk-unlink', ['email' => 'nobody@example.com']))->toBe(1);

        $priya->forceFill(['clerk_user_id' => 'user_old'])->save();
        $this->actingAs($priya)->postJson(route('tantra.team.clerk-unlink', $priya->id))->assertForbidden();
        $this->actingAs($owner)->postJson(route('tantra.team.clerk-unlink', $priya->id))->assertOk();
        expect($priya->refresh()->clerk_user_id)->toBeNull();
    });

    it('refetches the key set at most once a minute for unknown key ids', function () {
        $fetches = 0;
        Http::fake([CLERK_ISSUER.'/.well-known/jwks.json' => function () use (&$fetches) {
            $fetches++;

            return Http::response(clerkTestKey()['jwks']);
        }]);

        ClerkToken::verify(clerkToken());
        foreach ([1, 2, 3] as $attempt) {
            try {
                ClerkToken::verify(clerkToken(kid: 'ins_unknown'));
            } catch (InvalidClerkToken) {
            }
        }

        expect($fetches)->toBe(2);
        $this->travel(61)->seconds();
        try {
            ClerkToken::verify(clerkToken(kid: 'ins_unknown'));
        } catch (InvalidClerkToken) {
        }
        expect($fetches)->toBe(3);
    });

    it('keeps the cached key set when a forced refetch fails', function () {
        $responses = [Http::response(clerkTestKey()['jwks']), Http::response('down', 500)];
        Http::fake([CLERK_ISSUER.'/.well-known/jwks.json' => function () use (&$responses) {
            return array_shift($responses) ?? Http::response('down', 500);
        }]);

        ClerkToken::verify(clerkToken());
        try {
            ClerkToken::verify(clerkToken(kid: 'ins_unknown'));
        } catch (InvalidClerkToken) {
        }

        expect(ClerkToken::verify(clerkToken())['sub'])->toBe('user_clerk_1');
    });

    it('stores profile emails lowercase and unique whatever their case', function () {
        User::factory()->create(['email' => 'Taken@Example.com']);
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($user)->put(route('app.profile.update'), ['name' => $user->name, 'email' => 'TAKEN@example.com'])
            ->assertSessionHasErrors('email');
        $this->actingAs($user)->put(route('app.profile.update'), ['name' => $user->name, 'email' => ' New.Me@Example.COM '])
            ->assertSessionHasNoErrors();

        expect($user->refresh()->email)->toBe('new.me@example.com');
    });

    it('sends password resets and joined teammates to the password form', function () {
        $user = User::factory()->create(['email' => 'priya@usetantra.com']);
        $token = Password::createToken($user);

        $this->post(route('password.store'), ['token' => $token, 'email' => 'priya@usetantra.com', 'password' => 'A-much-longer-pass-123!', 'password_confirmation' => 'A-much-longer-pass-123!'])
            ->assertRedirect(route('login', ['password' => 1]))
            ->assertSessionHas('status');

        $this->get(route('login', ['password' => 1]))->assertInertia(fn (Assert $page) => $page->component('auth/Login')->has('status'));
    });
});
