<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Tantra\Channels\ChannelApp;
use App\Tantra\Channels\ChannelApps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
    $this->member = User::factory()->create(['account_id' => $this->owner->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->member->id, membershipPivot('member'));

    // The suite runs with readiness off (phpunit.xml); these tests are about it.
    config([
        'tantra.channel_apps.enforce' => true,
        'trypost.platforms.telegram.bot_token' => null,
        'trypost.platforms.telegram.bot_username' => null,
        // .env.testing carries Pinterest and TikTok keys; these tests need none.
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
    // Leave no snapshot behind for later tests in this process.
    ChannelApps::forgetEnv();
});

function connectOption(string $value): ?array
{
    return collect(Platform::connectableOptions())->firstWhere('value', $value);
}

it('lists every network, ready or not, with what is missing', function () {
    $values = collect(Platform::connectableOptions())->pluck('value')->all();

    expect($values)->toContain('x', 'facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'pinterest', 'bluesky', 'mastodon', 'telegram', 'discord', 'google_business', 'linkedin')
        ->and($values)->not->toContain('linkedin-page', 'instagram-facebook', 'zernio-linkedin-page', 'unipile-linkedin');

    expect(connectOption('bluesky')['ready'])->toBeTrue()
        ->and(connectOption('mastodon')['ready'])->toBeTrue()
        // .env.testing carries X keys: a key set on the server counts.
        ->and(connectOption('x')['ready'])->toBeTrue()
        ->and(connectOption('pinterest')['ready'])->toBeFalse()
        ->and(connectOption('pinterest')['setup']['missing'])->toBe(['App ID', 'App secret key'])
        ->and(connectOption('tiktok')['setup']['app_label'])->toBe('TikTok');
});

it('blocks the connect redirect of a network without keys', function () {
    $this->actingAs($this->owner)->get(route('app.social.pinterest.connect'))->assertForbidden();
});

it('makes a network connectable as soon as the owner saves its keys', function () {
    expect(Platform::Pinterest->isEnabled())->toBeFalse();

    $this->actingAs($this->owner)
        ->putJson(route('tantra.connectors.channels.update', 'pinterest'), ['client_id' => 'pin-client-id', 'client_secret' => 'x-secret-value-9876'])
        ->assertOk();

    expect(Platform::Pinterest->isEnabled())->toBeTrue()
        ->and(connectOption('pinterest')['ready'])->toBeTrue()
        ->and(config('services.pinterest.client_id'))->toBe('pin-client-id')
        ->and(config('services.pinterest.redirect'))->toEndWith('/accounts/pinterest/callback');

    // Encrypted at rest.
    $raw = DB::table('tantra_channel_apps')->where('app', 'pinterest')->value('credentials');
    expect($raw)->not->toContain('x-secret-value-9876');
});

it('never sends a saved secret to the page, only its last four', function () {
    ChannelApp::query()->create(['app' => 'meta_facebook', 'credentials' => ['client_id' => '12345', 'client_secret' => 'meta-secret-abcd']]);
    ChannelApps::forget();

    $response = $this->actingAs($this->owner)->get(route('tantra.connectors.index'));
    $response->assertInertia(fn (Assert $page) => $page
        ->where('channelApps', fn ($apps) => collect($apps)->firstWhere('key', 'meta_facebook')['fields'][1]['last_four'] === 'abcd')
    );
    expect($response->getContent())->not->toContain('meta-secret-abcd');
});

it('keeps the saved secret when the box is left empty', function () {
    ChannelApp::query()->create(['app' => 'x', 'credentials' => ['client_id' => 'old-id', 'client_secret' => 'kept-secret']]);
    ChannelApps::forget();

    $this->actingAs($this->owner)
        ->putJson(route('tantra.connectors.channels.update', 'x'), ['client_id' => 'new-id', 'client_secret' => ''])
        ->assertOk();

    expect(ChannelApp::query()->where('app', 'x')->first()->credentials)
        ->toBe(['client_id' => 'new-id', 'client_secret' => 'kept-secret']);
});

it('lets only the account owner change channel keys', function () {
    $this->actingAs($this->member)
        ->putJson(route('tantra.connectors.channels.update', 'x'), ['client_id' => 'a', 'client_secret' => 'b'])
        ->assertForbidden();
    $this->actingAs($this->member)
        ->deleteJson(route('tantra.connectors.channels.destroy', 'x'))
        ->assertForbidden();

    $this->actingAs($this->member)->get(route('tantra.connectors.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channelApps', fn ($apps) => collect($apps)->flatMap(fn ($a) => $a['fields'])->every(fn ($f) => $f['last_four'] === null && $f['value'] === null))
        );
});

it('removing the keys turns the network off again', function () {
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.channels.update', 'pinterest'), ['client_id' => 'id', 'client_secret' => 'secret']);
    expect(Platform::Pinterest->isEnabled())->toBeTrue();

    $this->actingAs($this->owner)->deleteJson(route('tantra.connectors.channels.destroy', 'pinterest'))->assertOk();

    expect(Platform::Pinterest->isEnabled())->toBeFalse()
        ->and(config('services.pinterest.client_id'))->toBeEmpty();
});

it('registers the Telegram webhook with a generated secret on save', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    $this->actingAs($this->owner)
        ->putJson(route('tantra.connectors.channels.update', 'telegram'), ['bot_token' => '123:abc', 'bot_username' => 'tantra_bot'])
        ->assertOk();

    expect(Platform::Telegram->isEnabled())->toBeTrue()
        ->and(strlen((string) config('trypost.platforms.telegram.webhook_secret')))->toBe(48);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:abc/setWebhook'));
});

it('keeps company pages off until Community Management is switched on', function () {
    $this->actingAs($this->owner)
        ->putJson(route('tantra.connectors.channels.update', 'linkedin'), ['client_id' => 'li', 'client_secret' => 'li-secret'])
        ->assertOk();

    expect(Platform::LinkedIn->isEnabled())->toBeTrue()
        ->and(Platform::LinkedInPage->isEnabled())->toBeFalse();

    $this->actingAs($this->owner)
        ->putJson(route('tantra.connectors.channels.update', 'linkedin'), ['community_management' => true])
        ->assertOk();

    expect(Platform::LinkedInPage->isEnabled())->toBeTrue();
});

it('shows the setup step owner flag only to the owner', function () {
    $this->actingAs($this->owner)->get(route('tantra.connectors.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tantraChannels.canSetUp', true));
    $this->actingAs($this->member)->get(route('tantra.connectors.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tantraChannels.canSetUp', false));
});

it('refuses the owner of any other account, such as an invited teammate\'s shell account', function () {
    $other = createApiTestToken()['user'];

    $this->actingAs($other)
        ->putJson(route('tantra.connectors.channels.update', 'pinterest'), ['client_id' => 'a', 'client_secret' => 'b'])
        ->assertForbidden();
    expect(ChannelApps::canManage($other))->toBeFalse()
        ->and(ChannelApps::canManage($this->owner))->toBeTrue();
});

it('caches the saved keys encrypted, never in plain text', function () {
    ChannelApp::query()->create(['app' => 'pinterest', 'credentials' => ['client_id' => 'pid', 'client_secret' => 'plain-secret-xyz']]);
    ChannelApps::forget();
    ChannelApps::saved();

    expect(serialize(Cache::get('tantra:channel-apps:v1')))->not->toContain('plain-secret-xyz');
});

it('stays out of config:cache so secrets never reach the cached config file', function () {
    ChannelApp::query()->create(['app' => 'pinterest', 'credentials' => ['client_id' => 'pid', 'client_secret' => 'cache-secret']]);
    ChannelApps::forget();
    config(['services.pinterest.client_secret' => null]);

    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', 'config:cache'];
    try {
        ChannelApps::apply();
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(config('services.pinterest.client_secret'))->toBeNull();
});
