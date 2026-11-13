<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Channels\ChannelApp;
use App\Tantra\Channels\ChannelApps;
use Inertia\Testing\AssertableInertia as Assert;

// tantra: the "Connect a channel" logo grid and the owner-only Developer apps
// page (Adi 2026-11-14). People see logos and connect; developer material
// (keys, portals, redirect URLs, catches) reaches only the instance owner.

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
    $this->member = User::factory()->create(['account_id' => $this->owner->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->member->id, membershipPivot('member'));

    config([
        'tantra.channel_apps.enforce' => true,
        'trypost.platforms.telegram.bot_token' => null,
        'trypost.platforms.telegram.bot_username' => null,
        'services.pinterest.client_id' => null,
        'services.pinterest.client_secret' => null,
        'services.tiktok.client_id' => null,
        'services.tiktok.client_secret' => null,
    ]);
    ChannelApps::snapshotEnv(fresh: true);
    ChannelApps::forget();
    ChannelApps::apply();
});

afterEach(function () {
    ChannelApps::forgetEnv();
});

function connectTiles(): array
{
    return [
        'linkedin', 'linkedin-page', 'x', 'facebook', 'instagram', 'threads', 'youtube',
        'tiktok', 'pinterest', 'bluesky', 'mastodon', 'telegram', 'discord', 'google_business',
    ];
}

/** Strings that only ever belong on the Developer apps page. */
function developerMarkers(): array
{
    $markers = ['/callback', 'Redirect URL', 'Developer portal', 'channelApps'];
    foreach (ChannelApps::catalog() as $entry) {
        $markers[] = $entry['portal'];
        if ($entry['catch'] !== null && strlen($entry['catch']) > 20) {
            $markers[] = $entry['catch'];
        }
    }

    return $markers;
}

it('lists every network on the connect grid with its ready and connected flags', function () {
    SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->bluesky()->count(2)->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id, 'status' => Status::TokenExpired]);

    $this->actingAs($this->member)->get(route('tantra.connectors.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tantraChannels.networks', function ($networks) {
                $networks = collect($networks)->keyBy('value');

                expect($networks->keys()->all())->toBe(connectTiles())
                    ->and($networks['bluesky'])->toMatchArray(['ready' => true, 'connected' => true, 'count' => 2, 'kind' => 'credentials'])
                    ->and($networks['mastodon'])->toMatchArray(['ready' => true, 'connected' => false, 'count' => 0, 'kind' => 'server'])
                    ->and($networks['x'])->toMatchArray(['ready' => true, 'connected' => true, 'count' => 1, 'kind' => 'oauth'])
                    // A lost connection is not "Connected".
                    ->and($networks['facebook'])->toMatchArray(['connected' => false, 'count' => 0])
                    ->and($networks['pinterest'])->toMatchArray(['ready' => false, 'connected' => false])
                    ->and($networks['telegram'])->toMatchArray(['ready' => false, 'kind' => 'bot'])
                    ->and($networks->every(fn ($n) => array_key_exists('ready', $n) && array_key_exists('connected', $n)))->toBeTrue();

                return true;
            })
        );
});

it('turns a tile on as soon as the owner saves its keys', function () {
    ChannelApp::query()->create(['app' => 'pinterest', 'credentials' => ['client_id' => 'pin-id', 'client_secret' => 'pin-secret']]);
    ChannelApps::forget();
    ChannelApps::apply();

    $this->actingAs($this->member)->get(route('tantra.connectors.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tantraChannels.networks', fn ($n) => collect($n)->firstWhere('value', 'pinterest')['ready'] === true)
        );
});

it('sends no developer data to people who are not the owner', function () {
    ChannelApp::query()->create(['app' => 'x', 'credentials' => ['client_id' => 'x-client-id-visible', 'client_secret' => 'x-secret-abcd1234']]);
    ChannelApps::forget();
    ChannelApps::apply();

    // A workspace admin who is not the instance owner may open the Channels page too.
    $admin = User::factory()->create(['account_id' => $this->owner->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($admin->id, membershipPivot('admin'));

    foreach ([[$this->member, route('tantra.connectors.index')], [$admin, route('tantra.connectors.index')], [$admin, route('app.workspace.channels')]] as [$user, $url]) {
        $response = $this->actingAs($user)->get($url)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('channelApps')
            ->missing('canManageChannels')
            ->where('tantraChannels.canSetUp', false)
            ->where('tantraChannels.networks', fn ($n) => collect($n)->every(fn ($t) => $t['setup_app'] === null))
            ->where('connectablePlatforms', fn ($p) => collect($p)->every(fn ($o) => ! array_key_exists('setup', $o)))
        );

        // Every prop the page gets, as plain text.
        $props = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        expect($props)->toContain('"networks"')
            ->and($props)->not->toContain('x-client-id-visible')
            ->and($props)->not->toContain('abcd1234');
        foreach (developerMarkers() as $marker) {
            expect($props)->not->toContain($marker);
        }
    }
});

it('gives the owner a set up link on tiles that are not ready, and nothing more', function () {
    $this->actingAs($this->owner)->get(route('tantra.connectors.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('channelApps')
            ->where('tantraChannels.canSetUp', true)
            ->where('tantraChannels.networks', function ($n) {
                $n = collect($n)->keyBy('value');

                expect($n['pinterest']['setup_app'])->toBe('pinterest')
                    ->and($n['tiktok']['setup_app'])->toBe('tiktok')
                    ->and($n['bluesky']['setup_app'])->toBeNull()
                    ->and($n['x']['setup_app'])->toBeNull();

                return true;
            })
        );
});

it('opens the Developer apps page for the owner with every developer detail', function () {
    $this->actingAs($this->owner)->get(route('tantra.developer-apps.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/developer-apps/Index')
            ->where('channelApps', fn ($apps) => collect($apps)->pluck('key')->all() === array_keys(ChannelApps::catalog())
                && collect($apps)->firstWhere('key', 'linkedin')['portal'] === 'https://www.linkedin.com/developers/apps'
                && str_ends_with(collect($apps)->firstWhere('key', 'linkedin')['callbacks'][0], '/accounts/linkedin/callback')
                && collect(collect($apps)->firstWhere('key', 'linkedin')['fields'])->contains('key', 'community_management'))
            ->where('linkedAccounts.total', 0)
        );
});

it('refuses the Developer apps page to everyone but the instance owner', function () {
    $this->actingAs($this->member)->get(route('tantra.developer-apps.index'))->assertForbidden();

    // The owner of another account (an invited teammate's shell account) too.
    $other = createApiTestToken()['user'];
    $this->actingAs($other)->get(route('tantra.developer-apps.index'))->assertForbidden();
});

it('sends guests to log in from the Developer apps page', function () {
    $this->get(route('tantra.developer-apps.index'))->assertRedirect();
});
