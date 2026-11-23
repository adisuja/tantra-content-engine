<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

// tantra: One Content app PRD 4.1 (critic B6), TANTRA_UI_OFF over real requests: UI GETs go to the CRM page, UI POSTs answer 410,
// allowlisted routes and everything outside the web group answer as before; with the switch off nothing changes.

const UI_OFF_CRM = 'https://crm.usetantra.com';
const UI_OFF_ID = '9b1d6a3e-1f0a-4c55-9d1e-3f1c2b7a8e01';

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->account);
    config(['tantra.crm.app_url' => UI_OFF_CRM]);
});

it('is off unless TANTRA_UI_OFF is set', function () {
    expect(config('tantra.ui_off.enabled'))->toBeFalse();
});

it('sends each UI screen to its CRM page with the switch on', function (string $path, string $target) {
    config(['tantra.ui_off.enabled' => true]);

    $this->actingAs($this->user)->get($path)->assertRedirect(UI_OFF_CRM.$target);
    // Signed out too: the switch runs ahead of the sign in check.
    auth()->logout();
    $this->get($path)->assertRedirect(UI_OFF_CRM.$target);
})->with([
    'home' => ['/', '/content'],
    'channels' => ['/settings/workspace/channels', '/content/channels'],
    'calendar' => ['/schedule', '/content/calendar'],
    'compose' => ['/posts/create', '/content/create?view=write'],
    'approvals' => ['/tantra/review', '/content/review'],
    'insights' => ['/insights', '/content/insights'],
    'ideas' => ['/create/ideas', '/content/create?view=ideas'],
    'settings' => ['/settings/workspace', '/content/settings'],
    'members' => ['/settings/workspace/members', '/content/brand/team'],
    'team' => ['/tantra/team', '/content/brand/team'],
    'reconnect' => ['/tantra/reconnect/'.UI_OFF_ID, '/content/channels?reconnect='.UI_OFF_ID],
    'approvals link' => ['/tantra/approvals/'.UI_OFF_ID, '/content/review'],
    'login' => ['/login', '/content'],
    'register' => ['/register', '/content'],
    'join' => ['/tantra/join/'.UI_OFF_ID, '/content'],
    'invite' => ['/invites/'.UI_OFF_ID, '/content'],
    'bluesky form' => ['/connect/bluesky', '/content/channels'],
]);

it('answers 410 to UI POSTs and other writes with the switch on', function (string $method, string $path) {
    config(['tantra.ui_off.enabled' => true]);

    $this->actingAs($this->user)->call($method, $path)->assertStatus(410);
    $this->actingAs($this->user)->json($method, $path)->assertStatus(410)->assertJsonPath('code', 'engine_ui_off');
})->with([
    ['POST', '/posts'],
    ['POST', '/login'],
    ['PUT', '/settings/workspace'],
    ['DELETE', '/channels/'.UI_OFF_ID],
    ['POST', '/tantra/review/items/'.UI_OFF_ID.'/approve'],
    ['POST', '/connect/bluesky'],
    ['POST', '/tantra/embed/session'],
]);

it('gives an Inertia visit a full page load on the CRM page', function () {
    config(['tantra.ui_off.enabled' => true]);

    $this->actingAs($this->user)->get('/schedule', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', UI_OFF_CRM.'/content/calendar');
});

it('leaves allowlisted routes and routes outside the web group as they were', function () {
    config(['tantra.ui_off.enabled' => true]);

    // Signed links still check their signature (403), not the switch.
    $this->get('/tantra/crm-connect?network=linkedin')->assertForbidden();
    $this->get('/tantra/canva/start')->assertForbidden();
    // The billing link finds its workspace first (404 for an unknown one), then checks the signature.
    $this->get('/tantra/billing/'.UI_OFF_ID.'/manage')->assertNotFound();
    // The CRM's way back with no round trip under way goes to the engine's default, never a 410.
    expect($this->actingAs($this->user)->get('/tantra/crm-connect/back')->getStatusCode())->not->toBe(410);
    // OAuth callbacks and the connect start reach their controllers.
    expect($this->actingAs($this->user)->get('/accounts/linkedin/callback')->headers->get('Location') ?? '')->not->toStartWith(UI_OFF_CRM);
    expect($this->actingAs($this->user)->get('/connect/linkedin')->headers->get('Location') ?? '')->not->toStartWith(UI_OFF_CRM);
    // The Telegram webhook, the preview link and Passport answer as before.
    expect($this->postJson('/telegram/webhook', [])->getStatusCode())->not->toBe(410);
    $this->get('/previewlink/'.str_repeat('a', 40))->assertNotFound();
    expect($this->get('/oauth/authorize')->headers->get('Location') ?? '')->not->toStartWith(UI_OFF_CRM);
    // Outside the web group.
    $this->get('/up')->assertOk();
    $this->getJson('/api/gateway/tantra/ops')->assertNotFound();
});

it('changes nothing with the switch off', function () {
    config(['tantra.ui_off.enabled' => false]);

    $this->get('/login')->assertOk();
    $this->get('/tantra/reconnect/'.UI_OFF_ID)->assertRedirect(route('login'));
    expect($this->actingAs($this->user)->get('/settings/workspace')->getStatusCode())->toBe(200)
        ->and($this->actingAs($this->user)->postJson('/posts', [])->getStatusCode())->not->toBe(410);
});
