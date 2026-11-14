<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Support\Social\PendingConnection;
use App\Tantra\Channels\ChannelApp;
use App\Tantra\Channels\ChannelApps;
use App\Tantra\Channels\ConnectAccessRequested;
use App\Tantra\Notify\SendToCrm;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * tantra: connecting a channel ends on a clear screen with the next step, for
 * every network and every way it can go wrong, and a network whose app is
 * still in testing shows the pre-connect step first.
 */

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
});

function seamlessSocialiteUser(string $id, array $approvedScopes, array $raw = ['account_type' => 'BUSINESS']): SocialiteUser
{
    $user = Mockery::mock(SocialiteUser::class);
    $user->shouldReceive('getId')->andReturn($id);
    $user->shouldReceive('getNickname')->andReturn('brand');
    $user->shouldReceive('getName')->andReturn('Brand');
    $user->shouldReceive('getAvatar')->andReturn(null);
    $user->token = 'access-token';
    $user->refreshToken = 'refresh-token';
    $user->expiresIn = 3600;
    $user->approvedScopes = $approvedScopes;
    $user->user = $raw;

    return $user;
}

function seamlessFakeSocialite(string $driver, SocialiteUser $user): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('usingGraphVersion')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
}

/** Every token request a Socialite driver makes answers with this. */
function seamlessProviderAnswers(string $service, int $status, array $body): void
{
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 5, new GuzzleResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($body)))));
    config()->set("services.{$service}.guzzle", ['handler' => $stack]);
}

function seamlessPlatform(string $routeName): Platform
{
    $segment = explode('.', $routeName)[2];

    return Platform::tryFrom($segment) ?? Platform::from(str_replace('-', '_', $segment));
}

/** The confirmation page as the person sees it after a callback. */
function seamlessConfirmation(Platform $platform): Assert
{
    $page = null;
    test()->get(route('app.social.connect.show', $platform))
        ->assertOk()
        ->assertInertia(function (Assert $inertia) use (&$page) {
            $page = $inertia;
        });

    return $page;
}

function seamlessEnforce(): void
{
    config(['tantra.channel_apps.enforce' => true]);
    ChannelApps::snapshotEnv(fresh: true);
    ChannelApps::forget();
    ChannelApps::apply();
}

afterEach(function () {
    ChannelApps::forgetEnv();
});

// ---- the network's own error on the callback --------------------------------

dataset('every oauth callback', [
    'linkedin' => ['app.social.linkedin.callback', []],
    'x' => ['app.social.x.callback', []],
    'tiktok' => ['app.social.tiktok.callback', []],
    'youtube' => ['app.social.youtube.callback', []],
    'facebook' => ['app.social.facebook.callback', []],
    'instagram' => ['app.social.instagram.callback', []],
    'instagram via facebook' => ['app.social.instagram-facebook.callback', []],
    'threads' => ['app.social.threads.callback', ['threads_oauth_state' => 'issued-state']],
    'pinterest' => ['app.social.pinterest.callback', []],
    'discord' => ['app.social.discord.callback', []],
    'google business' => ['app.social.google-business.callback', []],
    'mastodon' => ['app.social.mastodon.callback', [
        'mastodon_instance' => 'https://mastodon.example',
        'mastodon_client_id' => 'client-id',
        'mastodon_client_secret' => 'client-secret',
        'mastodon_oauth_state' => 'issued-state',
    ]],
]);

it('shows a denied consent as cancelled, with the next step, and asks the network nothing', function (string $routeName, array $session) {
    $platform = seamlessPlatform($routeName);
    Socialite::shouldReceive('driver')->never();
    Http::fake();
    startSocialConnect($this->workspace, $platform);
    session(['state' => 'issued-state', ...$session]);

    $this->actingAs($this->owner)
        ->get(route($routeName, ['error' => 'access_denied', 'error_reason' => 'user_denied', 'error_description' => 'The user denied your request.', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', $platform));

    seamlessConfirmation($platform)
        ->where('state', 'cancelled')
        ->where('tantraHelp.next', fn (string $next) => str_contains($next, 'Try again'));
    Http::assertNothingSent();
    expect(SocialAccount::query()->count())->toBe(0);
})->with('every oauth callback');

it('names a not-a-tester refusal and points to tester access while the app is in testing', function () {
    seamlessEnforce();
    startSocialConnect($this->workspace, Platform::Threads);
    session(['threads_oauth_state' => 'issued-state']);
    Http::fake();

    $this->actingAs($this->owner)
        ->get(route('app.social.threads.callback', [
            'error_code' => '1349245',
            'error_message' => 'Invalid Request: The user has not accepted the invite to test the app.',
            'state' => 'issued-state',
        ]))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBe('not_a_tester');
    seamlessConfirmation(Platform::Threads)
        ->where('state', 'error')
        ->where('reason', 'Threads says your account is not a tester of our app yet.')
        ->where('tantraHelp.detail', 'Invalid Request: The user has not accepted the invite to test the app.')
        ->where('tantraHelp.testing_url', route('tantra.connect.prepare', 'threads'))
        ->where('tantraHelp.next', fn (string $next) => str_contains($next, 'tester'));
    Http::assertNothingSent();
});

it('names each kind of network error on the callback', function (string $query, string $failure) {
    startSocialConnect($this->workspace, Platform::X);
    session(['state' => 'issued-state']);
    Socialite::shouldReceive('driver')->never();
    parse_str($query, $params);

    $this->actingAs($this->owner)
        ->get(route('app.social.x.callback', [...$params, 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    expect(socialConnectFailure())->toBe($failure);
})->with([
    'scope not on the app' => ['error=invalid_scope', 'app_setup'],
    'unknown client' => ['error=invalid_client', 'app_keys_rejected'],
    'outage' => ['error=temporarily_unavailable', 'provider_unavailable'],
    'sandbox' => ['error=unauthorized&error_description=This+account+is+not+a+sandbox+target+user', 'not_a_tester'],
    'anything else' => ['error=invalid_request&error_description=Something+odd', 'provider_error'],
]);

it('tells a Google Workspace user their admin blocks the app', function () {
    startSocialConnect($this->workspace, Platform::YouTube);
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->owner)->get(route('app.social.youtube.callback', ['error' => 'admin_policy_enforced']));

    expect(socialConnectFailure())->toBe('org_blocked');
    seamlessConfirmation(Platform::YouTube)->where('tantraHelp.link.url', 'https://support.google.com/a/answer/7281227');
});

// ---- state mismatch or lost session ------------------------------------------

dataset('socialite oauth callbacks', [
    'linkedin' => ['app.social.linkedin.callback', 'linkedin-openid'],
    'x' => ['app.social.x.callback', 'x'],
    'tiktok' => ['app.social.tiktok.callback', 'tiktok'],
    'youtube' => ['app.social.youtube.callback', 'google'],
    'facebook' => ['app.social.facebook.callback', 'facebook'],
    'instagram' => ['app.social.instagram.callback', 'instagram'],
    'instagram via facebook' => ['app.social.instagram-facebook.callback', 'facebook'],
    'pinterest' => ['app.social.pinterest.callback', 'pinterest'],
    'discord' => ['app.social.discord.callback', 'discord'],
    'google business' => ['app.social.google-business.callback', 'google-business'],
]);

it('explains a state mismatch instead of a generic error', function (string $routeName, string $service) {
    $platform = seamlessPlatform($routeName);
    seamlessProviderAnswers($service, 200, ['access_token' => 'never-used']);
    startSocialConnect($this->workspace, $platform);
    session(['state' => 'issued-state']);

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'code-1', 'state' => 'forged-state']))
        ->assertRedirect(route('app.social.connect.show', $platform));

    expect(socialConnectFailure())->toBe('invalid_state');
    seamlessConfirmation($platform)
        ->where('state', 'error')
        ->where('reason', 'This sign in came back to an old or different tab, so we stopped it to keep your account safe.')
        ->where('tantraHelp.next', 'Close any other tabs where you started connecting, then press Try again here.');
})->with('socialite oauth callbacks');

it('explains a state mismatch on the callbacks that check their own state', function (string $routeName, array $session) {
    $platform = seamlessPlatform($routeName);
    startSocialConnect($this->workspace, $platform);
    session($session);
    Http::fake();

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'code-1', 'state' => 'forged-state']))
        ->assertRedirect(route('app.social.connect.show', $platform));

    expect(socialConnectFailure())->toBe('invalid_state');
    seamlessConfirmation($platform)->where('reason', 'This sign in came back to an old or different tab, so we stopped it to keep your account safe.');
    Http::assertNothingSent();
})->with([
    'threads' => ['app.social.threads.callback', ['threads_oauth_state' => 'issued-state']],
    'mastodon' => ['app.social.mastodon.callback', [
        'mastodon_instance' => 'https://mastodon.example',
        'mastodon_client_id' => 'client-id',
        'mastodon_client_secret' => 'client-secret',
        'mastodon_oauth_state' => 'issued-state',
    ]],
]);

it('says a lost session expired and how to start again', function () {
    $this->actingAs($this->owner)->get(route('app.social.x.callback', ['code' => 'c', 'state' => 's']));

    seamlessConfirmation(Platform::X)
        ->where('state', 'expired')
        ->where('tantraHelp.next', 'Press Start again and finish the X sign in within 15 minutes.');
});

// ---- token exchange failures -------------------------------------------------

it('tells an expired code apart from other token failures', function (string $routeName, string $service) {
    $platform = seamlessPlatform($routeName);
    seamlessProviderAnswers($service, 400, ['error' => 'invalid_grant', 'error_description' => 'The authorization code has expired.']);
    Http::fake();
    startSocialConnect($this->workspace, $platform);
    session(['state' => 'issued-state']);

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'old-code', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', $platform));

    expect(socialConnectFailure())->toBe('code_expired')
        ->and(SocialAccount::query()->count())->toBe(0);
    seamlessConfirmation($platform)->where('tantraHelp.detail', 'The authorization code has expired.');
})->with('socialite oauth callbacks');

it('names the threads token failures', function (int $status, array $body, string $failure) {
    startSocialConnect($this->workspace, Platform::Threads);
    session(['threads_oauth_state' => 'issued-state']);
    Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response($body, $status),
    ]);

    $this->actingAs($this->owner)
        ->get(route('app.social.threads.callback', ['code' => 'c', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBe($failure);
})->with([
    'expired code' => [400, ['error' => ['message' => 'This authorization code has expired.', 'type' => 'OAuthException', 'code' => 100]], 'code_expired'],
    'rate limited' => [400, ['error' => ['message' => 'Application request limit reached', 'code' => 4]], 'rate_limited'],
    'wrong secret' => [400, ['error' => ['message' => 'Error validating client secret.', 'code' => 1]], 'app_keys_rejected'],
    'outage' => [503, ['error' => ['message' => 'Service unavailable']], 'provider_unavailable'],
    'unknown' => [400, ['error' => ['message' => 'Unsupported request']], 'error_connecting'],
]);

it('names the mastodon token failures', function (int $status, string $failure) {
    startSocialConnect($this->workspace, Platform::Mastodon);
    session([
        'mastodon_instance' => 'https://mastodon.example',
        'mastodon_client_id' => 'client-id',
        'mastodon_client_secret' => 'client-secret',
        'mastodon_oauth_state' => 'issued-state',
    ]);
    Http::fake(['https://mastodon.example/oauth/token' => Http::response(['error' => 'invalid_client'], $status)]);

    $this->actingAs($this->owner)
        ->get(route('app.social.mastodon.callback', ['code' => 'c', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    expect(socialConnectFailure())->toBe($failure);
})->with([
    // Mastodon registers its own app per instance: no keys for the owner to fix.
    'refused' => [401, 'failed_to_authenticate'],
    'down' => [502, 'provider_unavailable'],
    'busy' => [429, 'rate_limited'],
]);

// ---- missing or partly granted scopes ----------------------------------------

it('asks to connect again with every permission when a publish scope is left out', function (string $routeName, Closure $arrange) {
    $platform = seamlessPlatform($routeName);
    startSocialConnect($this->workspace, $platform);
    session(['threads_oauth_state' => 'issued-state', 'mastodon_instance' => 'https://mastodon.example', 'mastodon_client_id' => 'id', 'mastodon_client_secret' => 'secret', 'mastodon_oauth_state' => 'issued-state']);
    $arrange();

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'c', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', $platform));

    seamlessConfirmation($platform)
        ->where('state', 'missing_permission')
        ->where('tantraHelp.next', fn (string $next) => str_contains($next, 'leave every permission switched on'));
    expect(SocialAccount::query()->count())->toBe(0);
})->with([
    'x' => ['app.social.x.callback', fn () => seamlessFakeSocialite('x', seamlessSocialiteUser('x-1', ['tweet.read', 'users.read']))],
    'tiktok' => ['app.social.tiktok.callback', fn () => seamlessFakeSocialite('tiktok', seamlessSocialiteUser('tt-1', ['user.info.basic']))],
    'pinterest' => ['app.social.pinterest.callback', fn () => seamlessFakeSocialite('pinterest', seamlessSocialiteUser('pin-1', ['boards:read']))],
    'youtube' => ['app.social.youtube.callback', fn () => seamlessFakeSocialite('google', seamlessSocialiteUser('g-1', ['https://www.googleapis.com/auth/youtube.readonly']))],
    'instagram' => ['app.social.instagram.callback', fn () => seamlessFakeSocialite('instagram', seamlessSocialiteUser('ig-1', ['instagram_business_basic']))],
    'google business' => ['app.social.google-business.callback', fn () => seamlessFakeSocialite('google-business', seamlessSocialiteUser('gb-1', ['https://www.googleapis.com/auth/userinfo.profile']))],
    'facebook' => ['app.social.facebook.callback', function () {
        seamlessFakeSocialite('facebook', seamlessSocialiteUser('fb-1', []));
        Http::fake([config('trypost.platforms.facebook.graph_api').'/me/permissions*' => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'declined'],
        ]]), config('trypost.platforms.facebook.graph_api').'/*' => Http::response(['id' => 'fb-1'])]);
    }],
    'instagram via facebook' => ['app.social.instagram-facebook.callback', function () {
        seamlessFakeSocialite('facebook', seamlessSocialiteUser('fb-1', []));
        Http::fake([config('trypost.platforms.instagram-facebook.graph_api').'/me/permissions*' => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'instagram_content_publish', 'status' => 'declined'],
        ]]), config('trypost.platforms.instagram-facebook.graph_api').'/*' => Http::response(['id' => 'fb-1'])]);
    }],
    'threads' => ['app.social.threads.callback', fn () => Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response(['access_token' => 'short', 'user_id' => '1']),
        config('trypost.platforms.threads.auth_api').'/access_token*' => Http::response(['access_token' => 'long', 'expires_in' => 5184000]),
        config('trypost.platforms.threads.graph_api').'/debug_token*' => Http::response(['data' => ['scopes' => ['threads_basic']]]),
    ])],
    'mastodon' => ['app.social.mastodon.callback', fn () => Http::fake([
        'https://mastodon.example/oauth/token' => Http::response(['access_token' => 'm-token', 'scope' => 'read:accounts read:statuses']),
        'https://mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['id' => '1', 'acct' => 'brand', 'username' => 'brand']),
    ])],
]);

// ---- wrong account type, profile reads, rate limits ----------------------------

it('refuses a personal Instagram account and says how to switch', function () {
    startSocialConnect($this->workspace, Platform::Instagram);
    seamlessFakeSocialite('instagram', seamlessSocialiteUser('ig-1', ['instagram_business_basic', 'instagram_business_content_publish'], ['account_type' => 'PERSONAL']));

    $this->actingAs($this->owner)->get(route('app.social.instagram.callback'));

    expect(socialConnectFailure())->toBe('instagram_personal');
    seamlessConfirmation(Platform::Instagram)
        ->where('reason', 'This is a personal Instagram account. Only Business and Creator accounts can be connected.')
        ->where('tantraHelp.link.url', 'https://www.instagram.com/accounts/edit/');
});

it('no longer reads a failed YouTube channel lookup as no channel', function (int $status, array $body, string $failure) {
    startSocialConnect($this->workspace, Platform::YouTube);
    seamlessFakeSocialite('google', seamlessSocialiteUser('g-1', ['https://www.googleapis.com/auth/youtube.upload']));
    Http::fake([config('trypost.platforms.youtube.data_api').'/channels*' => Http::response($body, $status)]);

    $this->actingAs($this->owner)->get(route('app.social.youtube.callback'));

    expect(socialConnectFailure())->toBe($failure);
})->with([
    'quota' => [403, ['error' => ['code' => 403, 'errors' => [['reason' => 'quotaExceeded']], 'message' => 'Quota exceeded']], 'rate_limited'],
    'api off' => [403, ['error' => ['code' => 403, 'errors' => [['reason' => 'accessNotConfigured']], 'message' => 'YouTube Data API v3 has not been used in project 1 before']], 'app_setup'],
    'no channel yet' => [403, ['error' => ['code' => 403, 'errors' => [['reason' => 'youtubeSignupRequired']]]], 'no_youtube_channels'],
    'outage' => [500, ['error' => ['message' => 'Backend Error']], 'provider_unavailable'],
]);

it('says when Google has not opened the Business Profile API to the project', function () {
    startSocialConnect($this->workspace, Platform::GoogleBusiness);
    seamlessFakeSocialite('google-business', seamlessSocialiteUser('gb-1', ['https://www.googleapis.com/auth/business.manage']));
    Http::fake([config('trypost.platforms.google_business.account_management_api').'/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'My Business Account Management API has not been used in project 1 before or it is disabled.']], 403)]);

    $this->actingAs($this->owner)->get(route('app.social.google-business.callback'));

    expect(socialConnectFailure())->toBe('gbp_access_pending');
});

it('ends an unexpected crash on a callback on the confirmation page, not a 500', function () {
    startSocialConnect($this->workspace, Platform::X);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->andThrow(new TypeError('odd answer'));
    Socialite::shouldReceive('driver')->with('x')->andReturn($provider);

    $this->actingAs($this->owner)
        ->get(route('app.social.x.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    expect(socialConnectFailure())->toBe('error_connecting');
});

it('says when Discord was authorized without a server', function () {
    startSocialConnect($this->workspace, Platform::Discord);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('Discord authorization did not include a server.'));
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $this->actingAs($this->owner)->get(route('app.social.discord.callback'));

    expect(socialConnectFailure())->toBe('discord_no_server');
    seamlessConfirmation(Platform::Discord)->where('tantraHelp.next', fn (string $next) => str_contains($next, 'Manage Server'));
});

it('never shows a raw key for a reason without copy', function () {
    startSocialConnect($this->workspace, Platform::X)->fail('something_new');

    $this->actingAs($this->owner);
    seamlessConfirmation(Platform::X)->where('reason', 'Something went wrong while connecting X.');
});

it('tells Bluesky refusals apart from a wrong password', function (int $status, array $body, string $message) {
    Http::fake([config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.server.createSession' => Http::response($body, $status)]);

    $this->actingAs($this->owner)
        ->post(route('app.social.bluesky.store'), ['identifier' => 'brand.bsky.social', 'password' => 'abcd-efgh-ijkl-mnop'])
        ->assertSessionHasErrors(['password' => $message]);
})->with([
    'rate limited' => [429, ['error' => 'RateLimitExceeded'], 'Bluesky is limiting sign ins right now. Wait a few minutes, then try again.'],
    'email code' => [401, ['error' => 'AuthFactorTokenRequired'], 'Your Bluesky account asks for an email code at sign in. Use an app password instead: create one at bsky.app/settings/app-passwords.'],
    'suspended' => [400, ['error' => 'AccountTakedown'], 'Bluesky says this account is suspended or deactivated.'],
    'wrong password' => [401, ['error' => 'AuthenticationRequired'], 'Invalid credentials.'],
]);

// ---- the pre-connect step ------------------------------------------------------

it('starts these apps in testing unless the owner says otherwise', function () {
    $catalog = ChannelApps::catalog();

    foreach (['meta_threads', 'meta_facebook', 'meta_instagram', 'google_youtube', 'tiktok', 'pinterest'] as $app) {
        expect($catalog[$app]['mode'])->toBe('development')
            ->and(ChannelApps::mode($app))->toBe('development');
    }
    foreach (['linkedin', 'linkedin_page', 'x', 'google_business', 'discord', 'telegram'] as $app) {
        expect($catalog[$app]['mode'])->toBe('live');
    }
    expect($catalog['meta_threads']['testing']['accept'])->toBe('https://www.threads.net/settings/account')
        ->and($catalog['meta_facebook']['testing']['accept'])->toBe('https://developers.facebook.com/requests')
        ->and(ChannelApps::isLive(Platform::Bluesky->value))->toBeTrue();
});

it('shows the pre-connect step before the Threads sign in while the app is in testing', function () {
    seamlessEnforce();
    $member = workspaceMember($this->workspace, 'admin');

    $this->actingAs($member)
        ->get(route('app.social.threads.connect', ['return_to' => '/settings/workspace/channels']))
        ->assertRedirect(route('tantra.connect.prepare', ['threads', 'return_to' => '/settings/workspace/channels']));

    $this->get(route('tantra.connect.prepare', ['threads', 'return_to' => '/settings/workspace/channels']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/connect/Prepare')
            ->where('step.state', 'testing')
            ->where('step.network', 'Threads')
            ->where('step.accept_url', 'https://www.threads.net/settings/account')
            ->where('step.can_manage', false)
            // tantra: no developer console or catch for anyone but the owner.
            ->where('step.console_url', null)
            ->where('step.catch', null)
            ->where('step.back_url', url('/settings/workspace/channels'))
            ->where('step.your_steps', fn (string $text) => str_contains($text, 'Website permissions, then Invites'))
            ->where('step.continue_url', fn (string $url) => str_contains($url, '/connect/threads?') && str_contains($url, 'prepared=1') && str_contains($url, 'return_to='))
        );

    expect(PendingConnection::current())->toBeNull();
});

it('gives the owner the developer console and the Developer apps link on the step', function () {
    seamlessEnforce();

    $this->actingAs($this->owner)->get(route('tantra.connect.prepare', 'threads'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('step.can_manage', true)
            ->where('step.console_url', fn (?string $url) => filled($url))
            ->where('step.settings_url', url('/settings/developer-apps?channel=meta_threads#channels'))
        );
});

it('goes on to the sign in once the person presses Continue', function () {
    seamlessEnforce();

    $response = $this->actingAs($this->owner)->get(route('app.social.threads.connect', ['prepared' => '1']));

    expect($response->headers->get('Location'))->toStartWith(config('trypost.platforms.threads.oauth_url').'/oauth/authorize');
    expect(PendingConnection::current()?->platform())->toBe(Platform::Threads);
});

it('lets a reconnect through, since that account was let in before', function () {
    seamlessEnforce();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->owner)->get(route('app.social.threads.connect', ['reconnect' => $account->id]));

    expect($response->headers->get('Location'))->toStartWith(config('trypost.platforms.threads.oauth_url'));
});

it('skips the step once the owner marks the app live', function () {
    seamlessEnforce();

    $this->actingAs($this->owner)
        ->putJson(route('tantra.connectors.channels.update', 'meta_threads'), ['mode' => 'live'])
        ->assertOk()
        ->assertJsonPath('apps.4.mode', 'live');

    expect(ChannelApps::isLive(Platform::Threads->value))->toBeTrue();
    $response = $this->actingAs($this->owner)->get(route('app.social.threads.connect'));
    expect($response->headers->get('Location'))->toStartWith(config('trypost.platforms.threads.oauth_url'));
});

it('uses the same step for every network in testing, the Instagram methods included', function (string $route, string $slug) {
    config(['services.google.client_id' => 'g', 'services.google.client_secret' => 'g', 'services.instagram.client_id' => 'i', 'services.instagram.client_secret' => 'i']);
    seamlessEnforce();

    $this->actingAs($this->owner)->get(route($route))->assertRedirect(route('tantra.connect.prepare', $slug));
})->with([
    'facebook' => ['app.social.facebook.connect', 'facebook'],
    'instagram' => ['app.social.instagram.connect', 'instagram'],
    'instagram via facebook' => ['app.social.instagram-facebook.connect', 'instagram-facebook'],
    'youtube' => ['app.social.youtube.connect', 'youtube'],
    'tiktok' => ['app.social.tiktok.connect', 'tiktok'],
    'pinterest' => ['app.social.pinterest.connect', 'pinterest'],
]);

it('leaves live networks and networks without an app alone', function () {
    seamlessEnforce();

    $response = $this->actingAs($this->owner)->get(route('app.social.x.connect'));
    expect($response->headers->get('Location'))->not->toContain('/prepare');

    $this->actingAs($this->owner)->get(route('app.social.bluesky.connect'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounts/BlueskyConnect'));
});

it('shows the setup step, still as a refusal, when a network has no keys', function () {
    config(['services.pinterest.client_id' => null, 'services.pinterest.client_secret' => null]);
    seamlessEnforce();

    $this->actingAs($this->owner)->get(route('app.social.pinterest.connect'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/connect/Prepare')
            ->where('step.state', 'setup')
            ->where('step.continue_url', null)
            ->where('step.can_manage', true)
        );
});

it('still refuses a member who may not manage channels, with no step in the way', function () {
    seamlessEnforce();
    $member = workspaceMember($this->workspace, 'member');

    $this->actingAs($member)->get(route('app.social.threads.connect'))->assertForbidden();
});

it('emails the owner once when someone asks for tester access', function () {
    seamlessEnforce();
    config(['tantra.crm.intake_key' => null]);
    Mail::fake();
    $member = workspaceMember($this->workspace, 'admin', ['name' => 'Maya Rossi']);

    $this->actingAs($member)
        ->postJson(route('tantra.connect.request-access', 'threads'), ['handle' => '@maya.makes'])
        ->assertOk()
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Sent.'));
    $this->actingAs($member)
        ->postJson(route('tantra.connect.request-access', 'threads'), ['handle' => '@maya.makes'])
        ->assertOk()
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Already sent.'));

    Mail::assertQueued(ConnectAccessRequested::class, 1);
    Mail::assertQueued(ConnectAccessRequested::class, fn (ConnectAccessRequested $mail) => $mail->hasTo($this->owner->email)
        && $mail->network === 'Threads'
        && $mail->handle === '@maya.makes'
        && str_contains($mail->ownerSteps, 'Threads Tester'));
});

it('hands the request to the CRM as connect_access_requested when it is connected', function () {
    seamlessEnforce();
    config(['tantra.crm.intake_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    Bus::fake([SendToCrm::class]);
    $member = workspaceMember($this->workspace, 'admin');

    $this->actingAs($member)->postJson(route('tantra.connect.request-access', 'threads'), ['handle' => 'maya'])->assertOk();

    Bus::assertDispatched(SendToCrm::class, fn (SendToCrm $job) => $job->event === 'engine.connect_access_requested'
        && $job->userId === $this->owner->id
        && $job->data['network'] === 'threads'
        && $job->data['handle'] === 'maya'
        && $job->mail instanceof ConnectAccessRequested);
});

it('answers 404 for a network that has no step', function () {
    $this->actingAs($this->owner)->get(route('tantra.connect.prepare', 'bluesky'))->assertNotFound();
    $this->actingAs($this->owner)->postJson(route('tantra.connect.request-access', 'telegram'))->assertNotFound();
});

// ---- the mode setting ------------------------------------------------------------

it('lets only the owner set an app live or back to testing', function () {
    $member = workspaceMember($this->workspace, 'admin');

    $this->actingAs($member)->putJson(route('tantra.connectors.channels.update', 'meta_threads'), ['mode' => 'live'])->assertForbidden();
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.channels.update', 'meta_threads'), ['mode' => 'sometimes'])->assertUnprocessable();
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.channels.update', 'tiktok'), ['mode' => 'live'])->assertOk();

    expect(ChannelApp::query()->where('app', 'tiktok')->value('mode'))->toBe('live')
        ->and(ChannelApps::mode('tiktok'))->toBe('live');
});

it('keeps the chosen mode when the keys are removed, and shows tester steps in settings', function () {
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.channels.update', 'pinterest'), ['client_id' => 'p', 'client_secret' => 'p-secret', 'mode' => 'live'])->assertOk();
    $this->actingAs($this->owner)->deleteJson(route('tantra.connectors.channels.destroy', 'pinterest'))->assertOk();

    expect(ChannelApps::mode('pinterest'))->toBe('live');

    // tantra: the developer apps (and their mode) live on the owner's Developer apps page.
    $this->actingAs($this->owner)->get(route('tantra.developer-apps.index'))
        ->assertInertia(fn (Assert $page) => $page->where('channelApps', function ($apps) {
            $threads = collect($apps)->firstWhere('key', 'meta_threads');
            $pinterest = collect($apps)->firstWhere('key', 'pinterest');

            return $threads['mode'] === 'development'
                && str_contains($threads['testing']['owner'], 'Threads Tester')
                && str_contains($threads['testing']['live'], 'threads_content_publish')
                && $pinterest['mode'] === 'live'
                && $pinterest['saved_in_app'] === false;
        }));
});

it('writes the connect copy without dashes', function () {
    $copy = json_encode(require lang_path('en/tantra.php'), JSON_UNESCAPED_UNICODE);

    expect($copy)->not->toContain('—')->not->toContain('–');
});
