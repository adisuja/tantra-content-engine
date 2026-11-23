<?php

declare(strict_types=1);

use App\Enums\Media\CanvaPreset;
use App\Models\User;
use App\Support\MediaImportStatus;
use App\Tantra\Media\CanvaLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/GatewayHelpers.php';

// tantra: One Content app PRD 4.1, Design in Canva from the CRM Write media picker: the gateway start link, the signed engine
// link it opens, and the way back to the CRM with the result.

const CANVA_GW_RETURN = 'https://crm.usetantra.com/content/create?view=write';

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    oneGatewaySetUp($this);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->owner = $result['user'];
    $this->owner->forceFill(['clerk_user_id' => ONE_GW_CLERK])->save();
    config([
        'tantra.crm.app_url' => 'https://crm.usetantra.com',
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
        'trypost.media_sources.canva.api' => 'https://api.canva.test/rest/v1',
        'trypost.media_sources.canva.authorize_url' => 'https://www.canva.test/api/oauth/authorize',
        'trypost.media_sources.canva.editor_hosts' => '*.canva.test',
    ]);
});

function canvaGatewayStart(object $test, string $return = CANVA_GW_RETURN, ?string $preset = null): TestResponse
{
    $query = http_build_query(array_filter(['return' => $return, 'preset' => $preset]), '', '&', PHP_QUERY_RFC3986);

    return oneGatewayCall($test, 'GET', '/api/gateway/tantra/canva/start?'.$query);
}

it('answers a signed engine link and the nonce the result comes back under', function () {
    $response = canvaGatewayStart($this, preset: 'story')->assertOk()
        ->assertJsonPath('data.preset', 'story')
        ->assertJsonPath('data.expires_in', 600);

    expect($response->json('data.url'))->toStartWith(route('tantra.canva.start'))
        ->toContain('signature=')
        ->and($response->json('data.nonce'))->toMatch('/^[A-Za-z0-9]{32}$/');
    // Square when the CRM names no size.
    canvaGatewayStart($this)->assertJsonPath('data.preset', 'square');
});

it('refuses a return address outside Tantra Content, and answers 404 while Canva is not set up', function (string $return) {
    canvaGatewayStart($this, $return)->assertStatus(422)->assertJsonPath('code', 'bad_return');
})->with([
    'other host' => ['https://evil.test/content/create'],
    'not content' => ['https://crm.usetantra.com/deals'],
    'http' => ['http://crm.usetantra.com/content/create'],
    'fragment' => ['https://crm.usetantra.com/content/create#x'],
    'dot dot' => ['https://crm.usetantra.com/content/../deals'],
]);

it('answers 404 while Canva is not set up', function () {
    config(['trypost.media_sources.canva.enabled' => false]);

    canvaGatewayStart($this)->assertNotFound();
});

it('signs the person in once and opens Canva; a used link goes back with canva=failed', function () {
    $url = canvaGatewayStart($this, preset: 'portrait_4_5')->json('data.url');
    $nonce = (string) Str::of($url)->after('nonce=')->before('&');
    auth()->logout();

    $this->get($url)->assertRedirect(route('app.integrations.canva.designs.create', ['preset' => 'portrait_4_5', 'nonce' => $nonce]));
    expect(auth()->id())->toBe($this->owner->id);

    // Without a Canva connection the engine's own route starts Canva sign in.
    expect($this->get(route('app.integrations.canva.designs.create', ['preset' => 'portrait_4_5', 'nonce' => $nonce]))->headers->get('Location'))
        ->toStartWith('https://www.canva.test/api/oauth/authorize');

    $again = $this->get($url);
    expect($again->headers->get('Location'))->toStartWith('https://crm.usetantra.com/content/create?')
        ->toContain('canva=failed')->toContain('nonce='.$nonce);
});

it('refuses someone who may not make posts there', function () {
    $stranger = User::factory()->create();
    $url = CanvaLink::signedUrl($stranger, $this->owner->currentWorkspace, CanvaPreset::Square, 'crm-nonce-0123456789', CANVA_GW_RETURN);

    expect($this->get($url)->headers->get('Location'))->toContain('canva=failed');
    expect(auth()->check())->toBeFalse();
});

it('sends a popup that started from the CRM back there when Canva fails, and leaves the engine popup alone otherwise', function () {
    Http::fake(['https://api.canva.test/rest/v1/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $url = canvaGatewayStart($this)->json('data.url');
    $nonce = (string) Str::of($url)->after('nonce=')->before('&');
    $this->get($url);
    $this->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => $nonce]));

    $back = $this->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => session('canva_oauth.state')]));

    expect($back->headers->get('Location'))->toBe('https://crm.usetantra.com/content/create?view=write&canva=failed&nonce='.$nonce);
});

it('adds the import id to the way back once, and reads the import over the gateway', function () {
    session()->start();
    CanvaLink::remember('crm-nonce-0123456789', CANVA_GW_RETURN);

    expect(CanvaLink::take('crm-nonce-0123456789', true, 'imp-1'))
        ->toBe('https://crm.usetantra.com/content/create?view=write&canva=done&nonce=crm-nonce-0123456789&import=imp-1')
        ->and(CanvaLink::take('crm-nonce-0123456789', true, 'imp-1'))->toBeNull()
        ->and(CanvaLink::take('other-nonce-0123456789', true))->toBeNull();

    $import = (string) Str::uuid();
    MediaImportStatus::pending($import, $this->owner->id, $this->owner->currentWorkspace->id, null);
    oneGatewayCall($this, 'GET', '/api/gateway/tantra/media/imports/'.$import)->assertOk();
    oneGatewayCall($this, 'GET', '/api/gateway/tantra/media/imports/'.Str::uuid())->assertNotFound();
});
