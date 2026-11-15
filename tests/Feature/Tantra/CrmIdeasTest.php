<?php

declare(strict_types=1);

use App\Models\Idea;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Ideas\IdeaMeta;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's Content > Ideas reads the Engine server to server (POST /api/tantra/engagement/ideas).

beforeEach(function () {
    $owner = User::factory()->create();
    $this->owner = $owner;
    $this->team = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $stranger = User::factory()->create();
    $this->strangerUser = $stranger;
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id},ws_other={$this->other->id}",
    ]);
});

function crmIdea(object $test, Workspace $workspace, User $user, string $key, array $meta = [], array $idea = []): Idea
{
    $row = Idea::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'title' => "Idea {$key}", 'body' => "Body {$key}", ...$idea]);
    IdeaMeta::query()->create([
        'idea_id' => $row->id, 'account_id' => $user->account_id, 'external_key' => $key, 'platform' => 'linkedin', 'kind' => 'new', 'state' => 'idea',
        'hook' => "Hook {$key}", 'preview' => ['text' => "Post {$key}."], ...$meta,
    ]);

    return $row;
}

function crmIdeas(object $test, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))
        ->postJson('/api/tantra/engagement/ideas', $body);
}

it('answers 401 to a missing, wrong or unset key', function () {
    crmIdeas($this, ['workspace' => 'ws_team'], null)->assertUnauthorized();
    crmIdeas($this, ['workspace' => 'ws_team'], 'nope')->assertUnauthorized();
    config(['tantra.engagement_channels.key' => null]);
    crmIdeas($this, ['workspace' => 'ws_team'], '')->assertUnauthorized();
});

it('lists the ideas of the mapped team only, in the shape of the ideas API, and never caches them', function () {
    crmIdea($this, $this->team, $this->owner, 'mine-1', ['kind' => 'repurpose', 'state' => 'in_review']);
    crmIdea($this, $this->team, $this->owner, 'mine-2');
    crmIdea($this, $this->other, $this->strangerUser, 'theirs-1');

    $response = crmIdeas($this, ['workspace' => 'ws_team'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $response->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 1);
    $keys = collect($response->json('data'))->pluck('external_key')->all();
    expect($keys)->toEqualCanonicalizing(['mine-1', 'mine-2']);
    $first = collect($response->json('data'))->firstWhere('external_key', 'mine-1');
    expect($first)->toHaveKeys(['id', 'title', 'body', 'platform', 'kind', 'state', 'hook', 'preview', 'updated_at', 'review_url'])
        ->and($first['kind'])->toBe('repurpose')->and($first['state'])->toBe('in_review')->and($first['title'])->toBe('Idea mine-1')
        ->and($first['preview']['text'])->toBe('Post mine-1.');
});

it('gives an unknown or unmapped team an empty list, never a default workspace', function () {
    crmIdea($this, $this->team, $this->owner, 'mine-1');
    crmIdeas($this, ['workspace' => 'ws_unknown'])->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    crmIdeas($this, ['workspace' => 'ws_other'])->assertOk()->assertJsonCount(0, 'data');
});

it('pages the list and clamps a page to 100', function () {
    foreach (range(1, 5) as $n) {
        crmIdea($this, $this->team, $this->owner, "k{$n}");
    }
    crmIdeas($this, ['workspace' => 'ws_team', 'per_page' => 2, 'page' => 3])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.last_page', 3);
    crmIdeas($this, ['workspace' => 'ws_team', 'per_page' => 500])->assertOk()->assertJsonPath('meta.per_page', 100);
    crmIdeas($this, ['per_page' => 2])->assertUnprocessable();
    crmIdeas($this, ['workspace' => 'ws_team', 'per_page' => 0])->assertUnprocessable();
});
