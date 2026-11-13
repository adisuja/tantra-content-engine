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

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'Priya@usetantra.com'])])
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
        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'stranger@example.com'])])
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

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'priya@usetantra.com'])])
            ->assertRedirect(route('tantra.clerk.no-access'));

        $this->assertGuest();
    });

    it('refuses a bad token without signing in', function () {
        User::factory()->create(['email' => 'priya@usetantra.com']);

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'priya@usetantra.com', 'azp' => 'https://evil.example.com'])])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('clerk');

        $this->assertGuest();
    });

    it('creates the team login on its first sign in with every workspace', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        clerkWorkspace($owner, 'Priya');

        $this->post('/auth/clerk/session', ['token' => clerkToken(['email' => 'team@growthclubpro.com'])])->assertRedirect();

        $team = User::query()->where('email', 'team@growthclubpro.com')->firstOrFail();
        $this->assertAuthenticatedAs($team);
        expect($team->account_id)->toBe($owner->account_id)
            ->and($team->clerk_user_id)->toBe('user_clerk_1')
            ->and($team->accountWorkspaces()->pluck('name')->sort()->values()->all())->toBe(['Adi', 'Priya']);
    });

    it('signs out of Clerk too when signing out of the Engine', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login', ['signed_out' => 1]));
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

        Artisan::call('tantra:team-account', ['--owner' => true, '--account' => 'adi@growthclub.org']);

        expect(User::query()->where('email', 'team@growthclubpro.com')->firstOrFail()->isAccountOwner())->toBeTrue();
    });

    it('refuses an email that belongs to another Account', function () {
        $owner = User::factory()->create(['email' => 'adi@growthclub.org']);
        clerkWorkspace($owner, 'Adi');
        User::factory()->create(['email' => 'team@growthclubpro.com']);

        expect(Artisan::call('tantra:team-account', ['--account' => 'adi@growthclub.org']))->toBe(1)
            ->and(DB::table('user_workspace')->where('user_id', User::query()->where('email', 'team@growthclubpro.com')->value('id'))->count())->toBe(0);
    });
});
