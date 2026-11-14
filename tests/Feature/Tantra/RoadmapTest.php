<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Mcp\Servers\TryPostServer;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Webhook;
use App\Tantra\Mcp\Tools\RoadmapAddRequestTool;
use App\Tantra\Mcp\Tools\RoadmapListTool;
use App\Tantra\Mcp\Tools\RoadmapMoveTool;
use App\Tantra\Mcp\Tools\RoadmapUpdateTool;
use App\Tantra\Roadmap\Column;
use App\Tantra\Roadmap\Roadmap;
use App\Tantra\Roadmap\RoadmapItem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // The instance owner: the first Account's owner (ChannelApps::canManage).
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
    $this->ownerToken = $result['plain_token'];

    // Someone on another Account, created later: not the instance owner.
    $this->travel(1)->minutes();
    $other = createApiTestToken();
    $this->stranger = $other['user'];
    $this->strangerWorkspace = $other['workspace'];
    $this->strangerToken = $other['plain_token'];
});

function roadmapRequest(array $overrides = []): array
{
    return array_merge([
        'title' => 'Post to TikTok from the calendar',
        'description' => 'We export every video and upload it by hand.',
        'product' => 'content-engine',
        'name' => 'Priya Shah',
        'email' => 'priya@acme.test',
    ], $overrides);
}

it('seeds the real work in flight, with no dashes in our copy', function () {
    expect(RoadmapItem::query()->count())->toBe(15)
        ->and(RoadmapItem::query()->where('title', 'TikTok')->value('column'))->toBe(Column::Planned)
        ->and(RoadmapItem::query()->where('column', Column::Shipped)->whereNull('shipped_at')->exists())->toBeFalse();

    RoadmapItem::query()->get()->each(function (RoadmapItem $item): void {
        expect($item->title.' '.$item->description)->not->toMatch('/[\x{2013}\x{2014}]/u');
    });
});

it('renders the public page for guests with its SEO tags and a plain HTML copy of the board', function () {
    $response = $this->get('/roadmap')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('tantra/roadmap/Public')
        ->has('roadmap.items', 15)
        ->has('roadmap.columns', 5)
        ->where('roadmap.columns.0.label', 'Q: Requests')
        ->where('endpoints.requests', url('/api/roadmap/requests')));

    $html = $response->getContent();
    expect($html)
        ->toContain('<title>Tantra product roadmap: what we are building next</title>')
        ->toContain('<meta name="description"')
        ->toContain('<link rel="canonical" href="'.url('/roadmap').'">')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('Bluesky and Mastodon')
        ->not->toContain('noindex');
});

it('serves the board as JSON with the public shape and filters', function () {
    $this->getJson('/api/roadmap')
        ->assertOk()
        ->assertJsonCount(5, 'columns')
        ->assertJsonPath('columns.*.key', ['q', 'planned', 'in_progress', 'beta', 'shipped'])
        ->assertJsonPath('columns.0.label', 'Q: Requests')
        ->assertJsonStructure([
            'name', 'url', 'product', 'products', 'categories', 'generated_at',
            'columns' => [['key', 'label', 'blurb', 'count']],
            'items' => [['id', 'title', 'summary', 'detail', 'column', 'column_label', 'product', 'product_label', 'category', 'position', 'source', 'requested_by', 'created_at', 'updated_at', 'shipped_at']],
        ]);

    $this->getJson('/api/roadmap?column=shipped')->assertOk()->assertJsonCount(2, 'items')->assertJsonCount(1, 'columns');
    $this->getJson('/api/roadmap?product=crm')->assertOk()->assertJsonCount(0, 'items');
    $this->getJson('/api/roadmap?column=done')->assertUnprocessable();

    $this->get('/roadmap.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->assertJsonCount(15, 'items');
});

it('offers an Atom feed and an llms.txt for agents', function () {
    $feed = $this->get('/roadmap/feed')->assertOk();
    expect($feed->headers->get('Content-Type'))->toStartWith('application/atom+xml')
        ->and($feed->getContent())->toContain('Shipped: Team LinkedIn profiles through Unipile')
        ->toContain('New in Planned: TikTok');
    expect(simplexml_load_string($feed->getContent()))->not->toBeFalse();

    $this->get('/roadmap/llms.txt')->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('POST '.url('/api/roadmap/requests'), false)
        ->assertSee('roadmap_add_request', false)
        ->assertSee('roadmap.item_moved', false);
});

it('puts a request from the form in Q at once, as plain text', function () {
    $response = $this->postJson('/api/roadmap/requests', roadmapRequest([
        'title' => '<b>Post to TikTok</b> from the calendar',
        'description' => "<script>alert(1)</script>We do it by hand \u{2014} every day.",
    ]))->assertCreated()
        ->assertJsonPath('item.column', 'q')
        ->assertJsonPath('item.source', 'request')
        ->assertJsonPath('item.requested_by', 'Priya')
        ->assertJsonPath('item.title', 'Post to TikTok from the calendar');

    $item = RoadmapItem::query()->findOrFail($response->json('item.id'));
    expect($item->column)->toBe(Column::Q)
        ->and($item->description)->toBe('alert(1)We do it by hand, every day.')
        ->and($item->requester_email)->toBe('priya@acme.test');

    $this->getJson('/api/roadmap?column=q')->assertOk()->assertJsonPath('items.0.id', $item->id);
});

it('keeps the requester email and full name out of every public surface', function () {
    $this->postJson('/api/roadmap/requests', roadmapRequest())->assertCreated();

    foreach (['/roadmap', '/roadmap.json', '/api/roadmap', '/roadmap/feed', '/roadmap/llms.txt'] as $url) {
        $body = $this->get($url)->assertOk()->getContent();
        expect($body)->not->toContain('priya@acme.test', "{$url} leaks the email")
            ->not->toContain('Shah', "{$url} leaks the full name");
    }

    TryPostServer::actingAs($this->stranger)->tool(RoadmapListTool::class, [])
        ->assertOk()
        ->assertDontSee('priya@acme.test');
});

it('swallows a request that fills the honeypot', function () {
    $this->postJson('/api/roadmap/requests', roadmapRequest(['website' => 'https://spam.example']))
        ->assertCreated()
        ->assertJsonPath('item', null);

    expect(RoadmapItem::query()->where('source', 'request')->count())->toBe(0);
});

it('takes five requests an hour from one address', function () {
    foreach (range(1, Roadmap::REQUESTS_PER_HOUR) as $n) {
        $this->postJson('/api/roadmap/requests', roadmapRequest(['title' => "Request number {$n}"]))->assertCreated();
    }

    $this->postJson('/api/roadmap/requests', roadmapRequest(['title' => 'One too many']))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    expect(RoadmapItem::query()->where('source', 'request')->count())->toBe(Roadmap::REQUESTS_PER_HOUR);
});

it('limits the length of every field', function () {
    $this->postJson('/api/roadmap/requests', roadmapRequest(['title' => str_repeat('a', 121)]))->assertJsonValidationErrors('title');
    $this->postJson('/api/roadmap/requests', roadmapRequest(['title' => 'ok']))->assertJsonValidationErrors('title');
    $this->postJson('/api/roadmap/requests', roadmapRequest(['title' => '<p></p>   ']))->assertUnprocessable();
    $this->postJson('/api/roadmap/requests', roadmapRequest(['description' => str_repeat('a', 2001)]))->assertJsonValidationErrors('description');
    $this->postJson('/api/roadmap/requests', roadmapRequest(['email' => 'not an email']))->assertJsonValidationErrors('email');
    $this->postJson('/api/roadmap/requests', roadmapRequest(['product' => 'nope']))->assertJsonValidationErrors('product');
});

it('lets only the instance owner or a roadmap:write key change cards', function () {
    $item = RoadmapItem::query()->where('title', 'TikTok')->sole();

    $this->postJson('/api/roadmap/items', ['title' => 'Threads polls'])->assertUnauthorized();
    $this->withToken($this->strangerToken)->postJson('/api/roadmap/items', ['title' => 'Threads polls'])->assertForbidden();
    $this->withToken($this->strangerToken)->postJson("/api/roadmap/items/{$item->id}/move", ['column' => 'shipped'])->assertForbidden();
    $this->withToken($this->strangerToken)->deleteJson("/api/roadmap/items/{$item->id}")->assertForbidden();
    expect($item->refresh()->column)->toBe(Column::Planned);

    // A key with the scope may write, whoever it belongs to.
    $scoped = passportToken($this->stranger, $this->strangerWorkspace, [Roadmap::SCOPE]);
    app('auth')->forgetGuards();
    $this->withToken($scoped)->postJson("/api/roadmap/items/{$item->id}/move", ['column' => 'in_progress', 'position' => 1])
        ->assertOk()
        ->assertJsonPath('item.column', 'in_progress')
        ->assertJsonPath('item.position', 1)
        // Who asked stays with the owner.
        ->assertJsonMissingPath('item.requester');
});

it('refuses the scope on an OAuth grant and refuses expired keys', function () {
    $item = RoadmapItem::query()->where('title', 'TikTok')->sole();

    // Anyone can approve their own OAuth consent, so the scope there means nothing.
    $oauth = mcpBearerToken($this->stranger, $this->strangerWorkspace, ['mcp:use', Roadmap::SCOPE]);
    $this->withToken($oauth['plain_token'])->postJson("/api/roadmap/items/{$item->id}/move", ['column' => 'shipped'])->assertForbidden();

    // The owner's own key stops working once it expires.
    app('auth')->forgetGuards();
    AccessToken::query()->where('user_id', $this->owner->id)->update(['expires_at' => now()->subDay()]);
    $this->withToken($this->ownerToken)->postJson("/api/roadmap/items/{$item->id}/move", ['column' => 'shipped'])->assertForbidden();

    expect($item->refresh()->column)->toBe(Column::Planned);
});

it('keeps plain text that only looks like a tag', function () {
    $this->postJson('/api/roadmap/requests', roadmapRequest(['title' => 'Show x<y and I <3 this', 'description' => "Line\u{FFFF} one"]))
        ->assertCreated()
        ->assertJsonPath('item.title', 'Show x<y and I <3 this')
        ->assertJsonPath('item.summary', 'Line one');

    expect(simplexml_load_string($this->get('/roadmap/feed')->getContent()))->not->toBeFalse();
});

it('creates, edits, moves and deletes cards through the write API', function () {
    Queue::fake([DispatchWebhook::class]);
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::RoadmapItemMoved->value, EventType::RoadmapRequestCreated->value],
    ]);

    $id = $this->withToken($this->ownerToken)->postJson('/api/roadmap/items', [
        'title' => 'Threads polls', 'description' => 'Polls on Threads.', 'column' => 'planned', 'category' => 'Publishing',
    ])->assertCreated()->assertJsonPath('item.source', 'team')->json('item.id');

    $this->withToken($this->ownerToken)->postJson('/api/roadmap/items', ['title' => "Polls \u{2014} on Threads"])
        ->assertJsonValidationErrors('title');

    $this->withToken($this->ownerToken)->patchJson("/api/roadmap/items/{$id}", ['title' => 'Polls on Threads', 'category' => 'Channels'])
        ->assertOk()->assertJsonPath('item.title', 'Polls on Threads')->assertJsonPath('item.category', 'Channels');

    $this->withToken($this->ownerToken)->postJson("/api/roadmap/items/{$id}/move", ['column' => 'shipped'])
        ->assertOk()->assertJsonPath('item.column', 'shipped');
    expect(RoadmapItem::query()->find($id)->shipped_at)->not->toBeNull();

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'roadmap.item_moved'
        && $job->payload['from'] === 'planned' && $job->payload['to'] === 'shipped' && $job->payload['item']['id'] === $id);

    $this->withToken($this->ownerToken)->deleteJson("/api/roadmap/items/{$id}")->assertOk();
    expect(RoadmapItem::query()->find($id))->toBeNull();
});

it('sends a webhook with the public card when someone asks for a feature', function () {
    Queue::fake([DispatchWebhook::class]);
    Webhook::factory()->create(['workspace_id' => $this->workspace->id, 'events' => [EventType::RoadmapRequestCreated->value]]);
    // Another Account's webhooks never hear about the instance's roadmap.
    Webhook::factory()->create(['workspace_id' => $this->strangerWorkspace->id, 'events' => [EventType::RoadmapRequestCreated->value]]);

    $this->postJson('/api/roadmap/requests', roadmapRequest())->assertCreated();

    Queue::assertPushed(DispatchWebhook::class, 1);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === 'roadmap.request_created'
        && $job->webhook->workspace_id === $this->workspace->id
        && $job->payload['item']['requested_by'] === 'Priya'
        && ! str_contains(json_encode($job->payload), 'priya@acme.test'));
});

it('gives the owner a screen to manage cards and refuses everyone else', function () {
    $this->postJson('/api/roadmap/requests', roadmapRequest())->assertCreated();
    $item = RoadmapItem::query()->where('source', 'request')->sole();

    $this->actingAs($this->owner)->get('/settings/workspace/roadmap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/roadmap/Manage')
            ->has('items', 16)
            ->where('items', fn ($items) => collect($items)->firstWhere('id', $item->id)['requester']['email'] === 'priya@acme.test'));

    $this->actingAs($this->owner)->postJson("/settings/workspace/roadmap/items/{$item->id}/move", ['column' => 'planned'])
        ->assertOk()->assertJsonPath('item.column', 'planned');
    $this->actingAs($this->owner)->putJson("/settings/workspace/roadmap/items/{$item->id}", ['title' => 'TikTok from the calendar'])
        ->assertOk()->assertJsonPath('item.title', 'TikTok from the calendar');

    $member = User::factory()->create(['account_id' => $this->owner->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));
    $this->actingAs($member)->get('/settings/workspace/roadmap')->assertForbidden();
    $this->actingAs($member)->deleteJson("/settings/workspace/roadmap/items/{$item->id}")->assertForbidden();
    $this->actingAs($this->stranger)->get('/settings/workspace/roadmap')->assertForbidden();

    $this->actingAs($this->owner)->deleteJson("/settings/workspace/roadmap/items/{$item->id}")->assertOk();
    expect(RoadmapItem::query()->find($item->id))->toBeNull();
});

it('lists, takes requests, moves and edits through MCP with the same rules', function () {
    TryPostServer::actingAs($this->stranger)->tool(RoadmapListTool::class, ['column' => 'beta'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('items', 2)->etc());

    TryPostServer::actingAs($this->stranger)->tool(RoadmapAddRequestTool::class, ['title' => 'Schedule Reddit posts', 'description' => 'For our community.'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('item.column', 'q')->where('item.source', 'request')->etc());
    $request = RoadmapItem::query()->where('title', 'Schedule Reddit posts')->sole();
    expect($request->requester_email)->toBe($this->stranger->email);

    TryPostServer::actingAs($this->stranger)->tool(RoadmapMoveTool::class, ['id' => $request->id, 'column' => 'planned'])
        ->assertHasErrors(['Only the instance owner or a key with the roadmap:write scope can change the roadmap.']);
    TryPostServer::actingAs($this->stranger)->tool(RoadmapUpdateTool::class, ['id' => $request->id, 'title' => 'Reddit'])
        ->assertHasErrors();

    TryPostServer::actingAs($this->owner)->tool(RoadmapMoveTool::class, ['id' => $request->id, 'column' => 'planned'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('item.column', 'planned')->etc());

    TryPostServer::actingAs($this->owner)->tool(RoadmapUpdateTool::class, ['id' => $request->id, 'title' => "Reddit \u{2014} scheduling"])
        ->assertHasErrors(['No en or em dashes, please.']);
    TryPostServer::actingAs($this->owner)->tool(RoadmapUpdateTool::class, ['id' => $request->id, 'title' => 'Reddit scheduling', 'category' => 'Channels'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('item.title', 'Reddit scheduling')->where('item.category', 'Channels')->etc());

    TryPostServer::actingAs($this->owner)->tool(RoadmapListTool::class, ['column' => 'planned'])
        ->assertOk()
        ->assertSee($this->stranger->email);
});

it('issues a roadmap key from the command line', function () {
    $this->artisan('tantra:roadmap-key', ['email' => $this->stranger->email])->assertSuccessful();
    $this->artisan('tantra:roadmap-key', ['email' => 'nobody@example.test'])->assertFailed();

    expect($this->stranger->tokens()->where('name', 'Roadmap agent')->sole()->scopes)->toBe([Roadmap::SCOPE]);
});
