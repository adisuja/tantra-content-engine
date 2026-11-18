<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use App\Tantra\Channels\CrmConnectLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// tantra: RF-10, the Tantra app's "connect this network" link for step 4 of the referral funnel. The link signs the person in,
// runs the Engine's own connect flow and comes back to https://crm.usetantra.com/content/invite with the network and the result.
// Nothing reaches a network: Socialite and the X profile call are faked.

const RF10_RETURN = 'https://crm.usetantra.com/content/invite?connected=social';

beforeEach(function () {
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    $this->admin = workspaceMember($this->team, 'admin', ['email' => 'dana@example.test']);
    $this->member = workspaceMember($this->team, 'member', ['email' => 'mo@example.test']);
    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id}",
    ]);
    Http::fake([
        config('trypost.platforms.x.api').'/users/me*' => Http::response(['data' => ['id' => '123456789', 'subscription_type' => 'None']]),
        config('trypost.platforms.x.api').'/users/*' => Http::response(['data' => ['id' => '123456789', 'public_metrics' => ['followers_count' => 1]]]),
    ]);
});

function rf10Link(object $test, array $over = [], ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))
        ->postJson('/api/tantra/engagement/publish/connect', ['workspace' => 'ws_team', 'actor_email' => 'dana@example.test', 'network' => 'x', 'return_url' => RF10_RETURN, ...$over]);
}

/** The path and query of the signed link, to open it as the browser would. */
function rf10Path(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'].'?'.$parts['query'];
}

function rf10FakeX(): void
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789');
    $socialiteUser->shouldReceive('getNickname')->andReturn('adi');
    $socialiteUser->shouldReceive('getName')->andReturn('Adi');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 7200;
    $socialiteUser->approvedScopes = ['tweet.read', 'tweet.write', 'users.read', 'offline.access', 'media.write'];

    $driver = Mockery::mock();
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('with')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(Mockery::mock(['getTargetUrl' => 'https://x.com/i/oauth2/authorize?test=1']));
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('x')->andReturn($driver);
}

it('answers 401 without the shared key', function () {
    rf10Link($this, [], null)->assertUnauthorized();
    rf10Link($this, [], 'nope')->assertUnauthorized();
});

it('gives a signed link for one network, for a person who may add channels', function () {
    $response = rf10Link($this)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data.network'))->toBe('x')
        ->and($response->json('data.url'))->toStartWith(route('tantra.crm-connect'))
        ->toContain('signature=')->toContain('expires=')
        ->and($response->json('data.expires_at'))->not->toBeNull();
});

it('accepts only the invite page of crm.usetantra.com as the return URL', function (string $url) {
    rf10Link($this, ['return_url' => $url])->assertStatus(422)->assertJsonPath('error', 'invalid_return');
})->with([
    'another host' => 'https://evil.example.test/content/invite',
    'a look alike host' => 'https://crm.usetantra.com.evil.test/content/invite',
    'a sub domain' => 'https://x.crm.usetantra.com/content/invite',
    'plain http' => 'http://crm.usetantra.com/content/invite',
    'another path' => 'https://crm.usetantra.com/settings',
    'a longer path' => 'https://crm.usetantra.com/content/invite/../../logout',
    'the old path' => 'https://crm.usetantra.com/invite',
    'a port' => 'https://crm.usetantra.com:8443/content/invite',
    'a user' => 'https://me@crm.usetantra.com/content/invite',
    'a fragment' => 'https://crm.usetantra.com/content/invite#x',
    'a relative path' => '/content/invite',
    'a protocol relative URL' => '//crm.usetantra.com/content/invite',
    'a backslash' => 'https://crm.usetantra.com\\@evil.test/content/invite',
]);

it('has no link for a form network or one this Engine has no keys for, so the Tantra app falls back', function () {
    rf10Link($this, ['network' => 'bluesky'])->assertNotFound()->assertJsonPath('error', 'no_link');
    rf10Link($this, ['network' => 'mastodon'])->assertNotFound()->assertJsonPath('error', 'no_link');
    config(['trypost.platforms.pinterest.enabled' => false]);
    rf10Link($this, ['network' => 'pinterest'])->assertNotFound()->assertJsonPath('error', 'no_link');
    rf10Link($this, ['network' => 'myspace'])->assertStatus(422);
});

it('refuses an unknown person, an unmapped team and a person who may not add channels', function () {
    rf10Link($this, ['actor_email' => 'nobody@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    rf10Link($this, ['workspace' => 'ws_unknown'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    rf10Link($this, ['actor_email' => 'mo@example.test'])->assertForbidden()->assertJsonPath('error', 'cannot_connect');
});

it('signs the person in, opens their team and starts the Engine\'s own sign in for that network', function () {
    rf10FakeX();
    $elsewhere = Workspace::factory()->create(['account_id' => $this->admin->account_id, 'user_id' => $this->owner->id]);
    $this->admin->update(['current_workspace_id' => $elsewhere->id]);
    $url = rf10Link($this)->json('data.url');

    $this->get(rf10Path($url))->assertRedirect(route('app.social.x.connect', [CrmConnectLink::FLAG => '1']));
    $this->assertAuthenticatedAs($this->admin);
    expect($this->admin->fresh()->current_workspace_id)->toBe($this->team->id);

    $this->get(route('app.social.x.connect', [CrmConnectLink::FLAG => '1']))->assertRedirect('https://x.com/i/oauth2/authorize?test=1');
    expect(PendingConnection::current()?->workspaceId())->toBe($this->team->id);
});

it('works once, and not when changed', function () {
    $url = rf10Link($this)->json('data.url');
    $this->get(rf10Path($url).'x')->assertForbidden();
    $this->get(rf10Path(str_replace('network=x', 'network=facebook', $url)))->assertForbidden();
    $this->assertGuest();

    $this->get(rf10Path($url))->assertRedirect();
    auth()->logout();
    $this->get(rf10Path($url))->assertRedirect(RF10_RETURN.'&network=x&result=failed');
    $this->assertGuest();
});

it('ends another person\'s Engine session in this browser first', function () {
    $this->actingAs($this->owner);
    $url = rf10Link($this)->json('data.url');

    $this->get(rf10Path($url))->assertRedirect();
    $this->assertAuthenticatedAs($this->admin);
});

it('comes back to the invite page with result=connected once the network is connected', function () {
    rf10FakeX();
    $this->get(rf10Path(rf10Link($this)->json('data.url')));
    $this->get(route('app.social.x.connect', [CrmConnectLink::FLAG => '1']));
    $this->get(route('app.social.x.callback'))->assertRedirect(route('app.social.connect.show', Platform::X));

    // The confirmation page's Back goes to the invite page too.
    $this->get(route('app.social.connect.show', Platform::X))->assertOk()
        ->assertInertia(fn ($page) => $page->where('backUrl', route('tantra.crm-connect.back', ['result' => 'cancelled'])));

    finishSocialConnect(Platform::X)->assertRedirect(route('tantra.crm-connect.back', ['result' => 'connected']));
    expect($this->team->socialAccounts()->sole())->platform->toBe(Platform::X)->status->toBe(Status::Connected);

    $this->get(route('tantra.crm-connect.back', ['result' => 'connected']))
        ->assertRedirect(RF10_RETURN.'&network=x&result=connected');
});

it('gives an Inertia request (Finish, Back) a full page load to the invite page', function () {
    rf10FakeX();
    $this->get(rf10Path(rf10Link($this)->json('data.url')));
    $this->get(route('app.social.x.connect', [CrmConnectLink::FLAG => '1']));

    $this->withHeaders(['X-Inertia' => 'true'])->get(route('tantra.crm-connect.back', ['result' => 'cancelled']))
        ->assertStatus(409)->assertHeader('X-Inertia-Location', RF10_RETURN.'&network=x&result=cancelled');
});

it('comes back with result=cancelled when the person says no on the network', function () {
    rf10FakeX();
    $this->get(rf10Path(rf10Link($this)->json('data.url')));
    $this->get(route('app.social.x.connect', [CrmConnectLink::FLAG => '1']));

    $this->get(route('app.social.x.callback', ['error' => 'access_denied']))->assertRedirect(route('tantra.crm-connect.back', ['result' => 'cancelled']));
    $this->get(route('tantra.crm-connect.back', ['result' => 'cancelled']))->assertRedirect(RF10_RETURN.'&network=x&result=cancelled');
    expect(SocialAccount::query()->count())->toBe(0);
});

it('leaves every other connection as it was', function () {
    rf10FakeX();
    // A round trip that was left half way does not capture the next connection started in the Engine itself.
    $this->get(rf10Path(rf10Link($this)->json('data.url')));
    $this->get(route('app.social.x.connect', ['return_to' => '/settings/workspace/channels']));
    $this->get(route('app.social.x.callback'))->assertRedirect(route('app.social.connect.show', Platform::X));
    assertFinishedOnChannel(finishSocialConnect(Platform::X));

    $this->get(route('tantra.crm-connect.back', ['result' => 'connected']))->assertRedirect(PendingConnection::defaultReturnUrl());
});

it('keeps the invite page as the only place to go back to', function () {
    expect(CrmConnectLink::withResult('https://crm.usetantra.com/content/invite?connected=social&result=forged', 'x', 'connected'))
        ->toBe('https://crm.usetantra.com/content/invite?connected=social&result=connected&network=x')
        ->and(CrmConnectLink::resultFor('cancelled'))->toBe('cancelled')
        ->and(CrmConnectLink::resultFor('all_connected'))->toBe('connected')
        ->and(CrmConnectLink::resultFor('wrong_account'))->toBe('failed');
});
