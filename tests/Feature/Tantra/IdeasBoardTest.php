<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Models\Idea;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\UpsertIdeas;
use App\Tantra\Mcp\Tools\TantraIdeaToReviewTool;
use App\Tantra\Mcp\Tools\TantraListIdeasTool;
use App\Tantra\Mcp\Tools\TantraUpsertIdeasTool;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Mail::fake();

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'ben', 'review_owner_user_id' => $this->owner->id, 'timezone' => 'UTC']);
    SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn, 'timezone' => 'UTC']);

    $this->other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Aquila']);
    $this->other->members()->attach($this->owner->id, membershipPivot('admin'));
    WorkspaceSettings::query()->create(['workspace_id' => $this->other->id, 'brand_profile_key' => 'aquila', 'timezone' => 'UTC']);
});

/** @param array<string, mixed> $overrides */
function boardIdea(string $key, array $overrides = []): array
{
    return ['external_key' => $key, 'profile' => 'ben', 'platform' => 'linkedin', 'title' => "Idea {$key}", 'preview' => ['text' => "Post {$key}."], ...$overrides];
}

it('shows Tantra ideas on the board with their preview, the ready counts and the networks to connect', function () {
    UpsertIdeas::execute($this->owner, [
        boardIdea('li-1'),
        boardIdea('ig-1', ['platform' => 'instagram', 'kind' => 'repurpose', 'cadence_week' => 3, 'preview' => ['text' => 'Caption.', 'media' => ['type' => 'image', 'files' => ['https://cdn.example.com/a.jpg']]]]),
        boardIdea('ig-2', ['platform' => 'instagram', 'kind' => 'repurpose']),
    ]);
    Idea::factory()->create(['workspace_id' => $this->ws->id, 'idea_stage_id' => $this->ws->ideaStages()->value('id'), 'title' => 'Plain upstream idea']);
    $stage = Idea::query()->whereHas('tantraMeta', fn ($q) => $q->where('external_key', 'ig-1'))->value('idea_stage_id');

    $this->actingAs($this->owner)->get(route('app.create.ideas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('create/Ideas')
            ->where('tantraIdeas.ready.0.network', 'instagram')
            ->where('tantraIdeas.ready.0.count', 2)
            ->where('tantraIdeas.ready.0.connected', false)
            ->where('tantraIdeas.ready.1.network', 'linkedin')
            ->where('tantraIdeas.ready.1.connected', true)
            ->where('tantraIdeas.networks.facebook.connected', false)
            ->where('tantraIdeas.networks.facebook.label', 'Facebook')
            ->where('tantraIdeas.can_send', true)
            ->has("columns.{$stage}.data", 4)
            ->where("columns.{$stage}.data", fn ($cards) => collect($cards)->contains(fn ($c) => ($c['tantra']['external_key'] ?? null) === 'ig-1'
                && $c['tantra']['kind'] === 'repurpose'
                && $c['tantra']['cadence_week'] === 3
                && $c['tantra']['preview']['media']['files'] === ['https://cdn.example.com/a.jpg'])
                && collect($cards)->contains(fn ($c) => $c['title'] === 'Plain upstream idea' && ! array_key_exists('tantra', $c))));
});

it('leaves the board as upstream draws it in a workspace with no Tantra idea', function () {
    $this->actingAs($this->owner)->get(route('app.create.ideas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('create/Ideas')->missing('tantraIdeas'));
});

it('sends an idea to review from the board, once', function () {
    UpsertIdeas::execute($this->owner, [boardIdea('li-1')]);
    $idea = Idea::query()->sole();

    $this->actingAs($this->owner)->postJson(route('tantra.ideas.to-review', $idea))
        ->assertOk()
        ->assertJsonPath('message', 'Sent to review.');
    $this->actingAs($this->owner)->postJson(route('tantra.ideas.to-review', $idea))
        ->assertOk()
        ->assertJsonPath('message', 'Already in review.');

    expect(ReviewItem::query()->count())->toBe(1);

    UpsertIdeas::execute($this->owner, [boardIdea('tt-1', ['platform' => 'tiktok'])]);
    $tiktok = IdeaMeta::query()->where('external_key', 'tt-1')->sole()->idea_id;
    $this->actingAs($this->owner)->postJson(route('tantra.ideas.to-review', $tiktok))
        ->assertUnprocessable()
        ->assertJsonPath('errors.idea.0', 'Connect TikTok to publish first.');
});

it('lets the MCP tools upsert, list and send ideas, the owner across workspaces', function () {
    TryPostServer::actingAs($this->owner)->tool(TantraUpsertIdeasTool::class, ['ideas' => [boardIdea('mcp-1'), boardIdea('mcp-2', ['profile' => 'aquila', 'platform' => 'x'])]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('counts.created', 2)->etc());
    TryPostServer::actingAs($this->owner)->tool(TantraUpsertIdeasTool::class, ['ideas' => [boardIdea('mcp-1', ['title' => 'Again'])]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.0.outcome', 'updated')->etc());

    expect(Idea::query()->count())->toBe(2)
        ->and(IdeaMeta::query()->where('external_key', 'mcp-2')->sole()->idea->workspace_id)->toBe($this->other->id);

    TryPostServer::actingAs($this->owner)->tool(TantraListIdeasTool::class, ['platform' => 'x'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 1)->where('data.0.external_key', 'mcp-2')->etc());

    $id = IdeaMeta::query()->where('external_key', 'mcp-1')->value('idea_id');
    TryPostServer::actingAs($this->owner)->tool(TantraIdeaToReviewTool::class, ['idea_id' => $id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('outcome', 'created')->etc());
    TryPostServer::actingAs($this->owner)->tool(TantraIdeaToReviewTool::class, ['idea_id' => $id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('outcome', 'unchanged')->etc());

    expect(ReviewItem::query()->count())->toBe(1);
});

it('keeps a member who is not the owner to their current workspace', function () {
    UpsertIdeas::execute($this->owner, [boardIdea('ben-1'), boardIdea('aq-1', ['profile' => 'aquila'])]);
    $member = workspaceMember($this->ws, 'member');

    TryPostServer::actingAs($member)->tool(TantraListIdeasTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 1)->where('data.0.external_key', 'ben-1')->etc());
    TryPostServer::actingAs($member)->tool(TantraUpsertIdeasTool::class, ['ideas' => [boardIdea('aq-2', ['profile' => 'aquila'])]])
        ->assertHasErrors();

    $foreign = IdeaMeta::query()->where('external_key', 'aq-1')->value('idea_id');
    TryPostServer::actingAs($member)->tool(TantraIdeaToReviewTool::class, ['idea_id' => $foreign])
        ->assertHasErrors(['Idea not found.']);

    $outsider = workspaceOutsider($this->ws);
    TryPostServer::actingAs($outsider)->tool(TantraListIdeasTool::class, [])->assertHasErrors();

    expect(IdeaMeta::query()->where('external_key', 'aq-2')->exists())->toBeFalse();
});
