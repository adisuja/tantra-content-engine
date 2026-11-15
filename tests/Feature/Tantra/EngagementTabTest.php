<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// tantra: the Engagement tab, a view and editor on the Tantra CRM (PRD Engagement, phase 4). The CRM is faked.

beforeEach(function () {
    config(['tantra.crm.api_key' => 'ck_test_key', 'tantra.crm.url' => 'https://crm.test', 'tantra.crm.engagement.enabled' => true]);

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
    $this->owner->account->update(['owner_id' => $this->owner->id]);

    $this->mate = workspaceMember($this->workspace, 'member');
});

function crmPath(Request $r): string
{
    return (string) parse_url($r->url(), PHP_URL_PATH);
}

it('opens the tab for the main Account with the CRM connected and the key kept on the server', function () {
    Http::fake();

    $response = $this->actingAs($this->owner)->get('/tantra/engagement/people?kind=team')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/engagement/Index')
            ->where('tab', 'people')
            ->where('kind', 'team')
            ->where('crm.connected', true)
            ->where('crm.reason', null)
            ->where('canManage', true));

    expect($response->getContent())->not->toContain('ck_test_key');
    Http::assertNothingSent();
});

it('shows a teammate the tab without owner actions', function () {
    $this->actingAs($this->mate)->get('/tantra/engagement')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'posts')->where('crm.connected', true)->where('canManage', false));
});

it('says what is missing when the server has no CRM key, and calls nothing', function () {
    config(['tantra.crm.api_key' => null]);
    Http::fake();

    $this->actingAs($this->owner)->get('/tantra/engagement')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('crm.connected', false)->where('crm.reason', 'no_key'));

    $this->actingAs($this->owner)->getJson('/tantra/engagement/api/sources')
        ->assertStatus(409)
        ->assertJsonPath('code', 'crm_not_connected')
        ->assertJsonPath('reason', 'no_key');

    Http::assertNothingSent();
});

it('keeps another Account out of the team CRM workspace', function () {
    Http::fake();
    $other = User::factory()->create();
    $ws = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $ws->members()->attach($other->id, membershipPivot('admin'));
    $other->update(['current_workspace_id' => $ws->id]);

    $this->actingAs($other->fresh())->get('/tantra/engagement')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('crm.connected', false)->where('crm.reason', 'no_workspace'));
    $this->actingAs($other->fresh())->getJson('/tantra/engagement/api/people')->assertStatus(409)->assertJsonPath('reason', 'no_workspace');

    Http::assertNothingSent();
});

it('lists watched posts from the CRM with the server key', function () {
    Http::fake(['crm.test/api/engagement/sources' => Http::response(['sources' => [['id' => 'es_1', 'title' => 'PROMPTS', 'enabled' => false, 'stats' => ['people' => 4, 'team' => 1]]]])]);

    $this->actingAs($this->mate)->getJson('/tantra/engagement/api/sources')
        ->assertOk()
        ->assertJsonPath('sources.0.id', 'es_1')
        ->assertJsonPath('sources.0.stats.team', 1);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://crm.test/api/engagement/sources' && $r->hasHeader('Authorization', 'Bearer ck_test_key'));
});

it('asks the CRM for people by sub-tab, post and stage', function () {
    Http::fake(['crm.test/api/engagement/people*' => Http::response(['kind' => 'likers', 'counts' => ['likers' => 1], 'total' => 1, 'people' => [[
        'pkey' => 'li:abc', 'name' => 'Priya Sharma', 'url' => 'https://www.linkedin.com/in/priya', 'network_distance' => 'second', 'stage' => 'Engaged',
        'enrichment' => ['company' => 'Acme', 'title' => 'Founder', 'best_email' => 'priya@acme.com', 'email_confidence' => 82],
        'pending' => ['state' => 'due', 'label' => 'Invite due today'], 'team' => false,
    ]]])]);

    $this->actingAs($this->mate)->getJson('/tantra/engagement/api/people?group=likers&source_id=es_1&stage=Engaged&q=pri')
        ->assertOk()
        ->assertJsonPath('people.0.enrichment.email_confidence', 82)
        ->assertJsonPath('people.0.network_distance', 'second');

    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return crmPath($r) === '/api/engagement/people' && $q === ['group' => 'likers', 'source_id' => 'es_1', 'stage' => 'Engaged', 'q' => 'pri'];
    });
});

it('shows a clear state when the CRM does not answer or fails', function (Closure $fake) {
    Http::fake(['crm.test/*' => $fake()]);

    $this->actingAs($this->owner)->getJson('/tantra/engagement/api/pipeline')
        ->assertStatus(503)
        ->assertJsonPath('code', 'crm_unreachable')
        ->assertJsonPath('message', __('tantra.engagement.unreachable'));
})->with([
    'no answer' => fn () => fn () => Http::failedConnection('timed out'),
    'server error' => fn () => fn () => Http::response(['error' => 'boom'], 500),
]);

it('passes the CRM refusal on in its own words, and reads a refused key as not connected', function () {
    Http::fake([
        'crm.test/api/engagement/sources/es_gone' => Http::response(['error' => 'Source not found'], 404),
        'crm.test/api/engagement/sources' => Http::response(['error' => 'Invalid or revoked API key', 'code' => 'unauthenticated'], 401),
    ]);

    $this->actingAs($this->owner)->getJson('/tantra/engagement/api/sources/es_gone')->assertStatus(404)->assertJsonPath('message', 'Source not found');
    $this->actingAs($this->owner)->getJson('/tantra/engagement/api/sources')->assertStatus(409)->assertJsonPath('reason', 'key_refused');
});

it('approves and starts only on an owner-level click with confirm', function () {
    Http::fake(['crm.test/api/engagement/sources/es_1/approve' => Http::response(['restarted' => 2])]);

    $this->actingAs($this->mate)->postJson('/tantra/engagement/api/sources/es_1/approve', ['confirm' => true])->assertForbidden();
    $this->actingAs($this->owner)->postJson('/tantra/engagement/api/sources/es_1/approve')->assertUnprocessable();
    Http::assertNothingSent();

    $this->actingAs($this->owner)->postJson('/tantra/engagement/api/sources/es_1/approve', ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('restarted', 2);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && crmPath($r) === '/api/engagement/sources/es_1/approve');
});

it('turns a source on with the backfill choice, and never auto-approves', function () {
    Http::fake(['crm.test/*' => Http::response(['source' => ['id' => 'es_1', 'enabled' => true]])]);

    $this->actingAs($this->owner)->postJson('/tantra/engagement/api/sources/es_1/enable', ['enabled' => true, 'backfill' => 'requesters'])->assertOk();

    Http::assertSent(fn (Request $r) => crmPath($r) === '/api/engagement/sources/es_1/enable' && $r['enabled'] === true && $r['backfill'] === 'requesters');
    Http::assertNotSent(fn (Request $r) => str_ends_with(crmPath($r), '/approve'));
});

it('marks someone as team through the CRM', function () {
    Http::fake(['crm.test/api/engagement/people/team' => Http::response(['stopped' => 1, 'released' => 0])]);

    $this->actingAs($this->mate)->postJson('/tantra/engagement/api/people/team', ['deal_id' => 'dl_1', 'team' => true])->assertForbidden();
    $this->actingAs($this->owner)->postJson('/tantra/engagement/api/people/team', ['deal_id' => 'dl_1', 'person' => 'li:abc', 'team' => true])
        ->assertOk()->assertJsonPath('stopped', 1);

    Http::assertSent(fn (Request $r) => $r['deal_id'] === 'dl_1' && $r['person'] === 'li:abc' && $r['team'] === true);
});

it('moves a person to another stage, sending only the stage', function () {
    Http::fake(['crm.test/api/deals/dl_1' => Http::response(['deal' => ['id' => 'dl_1']])]);

    $this->actingAs($this->owner)->postJson('/tantra/engagement/api/pipeline/dl_1/move', ['stage_id' => 'stg_2', 'title' => 'hijack'])->assertOk();

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && crmPath($r) === '/api/deals/dl_1' && $r->data() === ['stage_id' => 'stg_2']);
});

it('edits a sequence in place without touching its test mode', function () {
    Http::fake([
        'crm.test/api/engagement/sequence-board' => Http::response(['pipeline_id' => 'p1', 'stages' => [['id' => 'st1', 'name' => 'Requested', 'sequences' => []]]]),
        'crm.test/api/automation/sequences/sq_1' => Http::response(['sequence' => ['id' => 'sq_1', 'name' => 'Giveaway', 'status' => 'draft', 'steps' => [], 'settings' => ['dry_run' => true]]]),
    ]);

    $this->actingAs($this->mate)->getJson('/tantra/engagement/api/sequence-board')->assertOk()->assertJsonPath('stages.0.name', 'Requested');

    $step = ['id' => 'st_abcdef1234', 'channel' => 'linkedin', 'action' => 'send_message', 'wait' => ['amount' => 1, 'unit' => 'days'], 'body' => 'Hi {{firstName}}', 'conditions' => [['field' => 'x', 'op' => 'is_set']]];
    $this->actingAs($this->owner)->patchJson('/tantra/engagement/api/sequences/sq_1', [
        'name' => 'Giveaway', 'steps' => [$step], 'settings' => ['dry_run' => false],
    ])->assertOk()->assertJsonPath('sequence.settings.dry_run', true);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && crmPath($r) === '/api/automation/sequences/sq_1'
        && ! array_key_exists('settings', $r->data())
        && $r['steps'][0]['conditions'][0]['field'] === 'x'
        && $r['steps'][0]['body'] === 'Hi {{firstName}}');

    $this->actingAs($this->mate)->patchJson('/tantra/engagement/api/sequences/sq_1', ['status' => 'active'])->assertForbidden();
});
