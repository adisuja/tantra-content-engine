<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Support\Social\PendingConnection;
use App\Tantra\Channels\LinkedInPageApp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * tantra: company pages connect through their own LinkedIn app when it has keys.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));

    config([
        'services.linkedin.client_id' => 'profile-app',
        'services.linkedin-openid.client_id' => 'profile-app',
        'services.linkedin-page.client_id' => 'page-app',
        'services.linkedin-page.client_secret' => 'page-secret',
        'services.linkedin-page.redirect' => 'https://engine.test/accounts/linkedin/callback',
    ]);
});

function linkedInLocation($response): string
{
    return urldecode((string) $response->headers->get('Location') ?: (string) $response->headers->get('X-Inertia-Location'));
}

test('without page keys of their own, pages share the single LinkedIn consent screen', function () {
    config(['services.linkedin-page.client_id' => 'profile-app']);

    expect(LinkedInPageApp::separate())->toBeFalse();

    $location = linkedInLocation($this->actingAs($this->user)->get(route('app.social.linkedin.connect')));

    expect($location)->toContain('client_id=profile-app')->toContain('rw_organization_admin')->toContain('w_member_social');
});

test('with a separate page app a fresh connect asks for the profile first', function () {
    $location = linkedInLocation($this->actingAs($this->user)->get(route('app.social.linkedin.connect')));

    expect($location)->toContain('client_id=profile-app')->not->toContain('rw_organization_admin')
        ->and(session('tantra_linkedin_kind'))->toBe(LinkedInPageApp::PROFILE_THEN_PAGE);
});

test('a page reconnect goes straight to the page app without openid scopes', function () {
    $page = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $this->workspace->id, 'platform_user_id' => '123456']);

    $location = linkedInLocation($this->actingAs($this->user)->get(route('app.social.linkedin.connect', ['reconnect' => $page->id])));

    expect($location)->toStartWith('https://www.linkedin.com/oauth/v2/authorization')
        ->toContain('client_id=page-app')
        ->toContain('rw_organization_admin')
        ->not->toContain('openid')
        ->not->toContain('w_member_social');
});

test('the profile callback moves on to the page app, then offers the profile and the pages together', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    session(['tantra_linkedin_kind' => LinkedInPageApp::PROFILE_THEN_PAGE]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('person-123');
    $socialiteUser->shouldReceive('getName')->andReturn('John Doe');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'profile-token';
    $socialiteUser->refreshToken = null;
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->approvedScopes = ['openid', 'profile', 'w_member_social'];
    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/me*' => Http::response(['id' => 'person-123', 'vanityName' => 'johndoe']),
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response(['access_token' => 'page-token', 'expires_in' => 5184000, 'refresh_token' => 'page-refresh', 'scope' => 'r_organization_social,w_organization_social']),
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response(['elements' => [['organization~' => ['id' => 123456, 'localizedName' => 'Tantra']]]]),
    ]);

    $toPage = $this->actingAs($this->user)->get(route('app.social.linkedin.callback'));
    expect(linkedInLocation($toPage))->toContain('client_id=page-app');
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'organizationAcls'));

    parse_str((string) parse_url((string) $toPage->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get(route('app.social.linkedin.callback', ['code' => 'abc', 'state' => $query['state']]))
        ->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123', 'linkedin-page:123456']);
    expect(data_get(collect(PendingConnection::current()->identities())->firstWhere('platform', 'linkedin-page'), 'attributes.meta.oauth_app'))->toBe('linkedin_page');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/oauth/v2/accessToken')
        && $r['client_id'] === 'page-app' && $r['client_secret'] === 'page-secret' && $r['code'] === 'abc');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'organizationAcls') && $r->header('Authorization')[0] === 'Bearer page-token');
});

test('a page callback with a forged state connects nothing', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    session(['tantra_linkedin_kind' => LinkedInPageApp::PAGE, 'tantra_linkedin_page_state' => 'right']);
    Http::fake();

    $this->actingAs($this->user)->get(route('app.social.linkedin.callback', ['code' => 'abc', 'state' => 'wrong']));

    Http::assertNothingSent();
    expect(socialConnectFailure())->toBe('invalid_state');
});

function refreshLinkedInPage(array $meta): Request
{
    config(['services.linkedin.client_secret' => 'profile-secret']);
    $page = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => test()->workspace->id,
        'refresh_token' => 'r1',
        'token_expires_at' => now()->subMinute(),
        'meta' => ['organization_id' => '1', ...$meta],
    ]);
    Http::fake([config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response(['access_token' => 'new', 'expires_in' => 3600])]);

    app(ConnectionVerifier::class)->refreshToken($page);

    return collect(Http::recorded())->map(fn (array $pair) => $pair[0])->first(fn (Request $r): bool => str_ends_with($r->url(), '/accessToken'));
}

test('a company page stamped with the page app refreshes with the page app keys', function () {
    $request = refreshLinkedInPage(['oauth_app' => 'linkedin_page']);

    expect($request['client_id'])->toBe('page-app')->and($request['client_secret'])->toBe('page-secret');
});

test('a company page connected before the stamp refreshes with the profile app keys', function () {
    $request = refreshLinkedInPage([]);

    expect($request['client_id'])->toBe('profile-app')->and($request['client_secret'])->toBe('profile-secret');
});

test('the page app keys fall back to the profile app keys at the point of use', function () {
    config(['services.linkedin-page.client_id' => null, 'services.linkedin-page.client_secret' => null]);

    $request = refreshLinkedInPage(['oauth_app' => 'linkedin_page']);

    expect($request['client_id'])->toBe('profile-app')->and($request['client_secret'])->toBe('profile-secret')
        ->and(LinkedInPageApp::pageKeys())->toBe(['client_id' => 'profile-app', 'client_secret' => 'profile-secret']);
});
