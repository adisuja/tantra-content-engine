<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

// tantra: the two changes the Tantra app's Content > Drafts makes: the text of a draft, and sending a draft for approval.

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    // One person who publishes directly, one whose posts need approval, one who is on another team.
    $this->direct = workspaceMember($this->team, 'member', ['email' => 'dana@example.test']);
    $this->asker = workspaceMember($this->team, 'approval', ['email' => 'ann@example.test']);
    $stranger = User::factory()->create(['email' => 'stan@example.test']);
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn]);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id},ws_other={$this->other->id}",
    ]);
});

function crmDraft(object $test, string $content = 'A draft about timing.', array $state = []): Post
{
    return Post::factory()->forAccount($test->linkedin)->create(['workspace_id' => $test->team->id, 'user_id' => $test->owner->id, 'content' => $content, 'status' => PostStatus::Draft, ...$state]);
}

function crmAct(object $test, string $path, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))
        ->postJson("/api/tantra/engagement/posts/{$path}", $body);
}

function inDays(int $days): string
{
    return now()->addDays($days)->setTime(9, 30)->utc()->toIso8601String();
}

it('answers 401 to a missing or wrong key on both', function () {
    $post = crmDraft($this);
    crmAct($this, 'update', ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test', 'post' => $post->id, 'content' => 'x'], null)->assertUnauthorized();
    crmAct($this, 'approval', ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test', 'post' => $post->id, 'scheduled_at' => inDays(2)], 'nope')->assertUnauthorized();
    expect($post->fresh()->content)->toBe('A draft about timing.');
});

it('changes the text of a draft as that person, and nothing else', function () {
    $post = crmDraft($this);
    $before = $post->only(['status', 'scheduled_at', 'social_account_id', 'platform']);

    $response = crmAct($this, 'update', ['workspace' => 'ws_team', 'actor_email' => 'Ann@Example.test', 'post' => $post->id, 'content' => 'A sharper first line.'])->assertOk();
    $response->assertJsonPath('data.content', 'A sharper first line.')->assertJsonPath('data.status', 'draft')->assertHeader('Cache-Control', 'no-store, private');
    $fresh = $post->fresh();
    expect($fresh->content)->toBe('A sharper first line.')->and($fresh->only(['status', 'scheduled_at', 'social_account_id', 'platform']))->toEqual($before);
});

it('refuses to change a post that is not a draft, an unknown person, an unknown post and another team\'s post', function () {
    $waiting = crmDraft($this, 'Waiting.', ['status' => PostStatus::PendingApproval, 'scheduled_at' => now()->addDay()]);
    $published = crmDraft($this, 'Out.', ['status' => PostStatus::Published, 'published_at' => now()->subDay()]);
    $draft = crmDraft($this);
    $body = ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test', 'content' => 'New text'];

    crmAct($this, 'update', [...$body, 'post' => $waiting->id])->assertStatus(409)->assertJsonPath('error', 'not_a_draft');
    crmAct($this, 'update', [...$body, 'post' => $published->id])->assertStatus(409)->assertJsonPath('error', 'not_a_draft');
    crmAct($this, 'update', [...$body, 'post' => $draft->id, 'actor_email' => 'stan@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmAct($this, 'update', [...$body, 'post' => $draft->id, 'actor_email' => 'nobody@example.test'])->assertForbidden();
    crmAct($this, 'update', [...$body, 'post' => (string) \Illuminate\Support\Str::uuid()])->assertNotFound()->assertJsonPath('error', 'not_found');
    crmAct($this, 'update', [...$body, 'post' => $draft->id, 'workspace' => 'ws_other'])->assertNotFound();
    crmAct($this, 'update', [...$body, 'post' => $draft->id, 'workspace' => 'ws_unknown'])->assertNotFound();
    expect($waiting->fresh()->content)->toBe('Waiting.')->and($published->fresh()->content)->toBe('Out.')->and($draft->fresh()->content)->toBe('A draft about timing.');
});

it('validates the text and the person', function () {
    $post = crmDraft($this);
    $base = ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test', 'post' => $post->id];
    crmAct($this, 'update', [...$base, 'content' => ''])->assertUnprocessable();
    crmAct($this, 'update', [...$base, 'content' => str_repeat('x', 10001)])->assertUnprocessable();
    crmAct($this, 'update', [...$base, 'actor_email' => 'not an email', 'content' => 'x'])->assertUnprocessable();
    crmAct($this, 'update', ['workspace' => 'ws_team', 'post' => 'nope', 'content' => 'x', 'actor_email' => 'ann@example.test'])->assertUnprocessable();
});

it('sends a draft for approval at the time given, for a person whose posts need approval', function () {
    $post = crmDraft($this);
    $when = inDays(2);

    $response = crmAct($this, 'approval', ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test', 'post' => $post->id, 'scheduled_at' => $when])->assertOk();
    $response->assertJsonPath('data.status', 'pending_approval');
    $fresh = $post->fresh();
    expect($fresh->status)->toBe(PostStatus::PendingApproval)->and($fresh->scheduled_at->utc()->toIso8601String())->toBe(\Carbon\CarbonImmutable::parse($when)->utc()->toIso8601String())
        ->and($fresh->content)->toBe('A draft about timing.');
});

it('never schedules for a person who publishes directly: approval is not required', function () {
    $post = crmDraft($this);
    crmAct($this, 'approval', ['workspace' => 'ws_team', 'actor_email' => 'dana@example.test', 'post' => $post->id, 'scheduled_at' => inDays(2)])
        ->assertStatus(409)->assertJsonPath('error', 'approval_not_required');
    $fresh = $post->fresh();
    expect($fresh->status)->toBe(PostStatus::Draft)->and($fresh->scheduled_at)->toBeNull();
});

it('refuses a past time, an empty draft, a post that is not a draft and an unknown person on approval', function () {
    $draft = crmDraft($this);
    $empty = crmDraft($this, '   ');
    $waiting = crmDraft($this, 'Waiting.', ['status' => PostStatus::PendingApproval, 'scheduled_at' => now()->addDay()]);
    $base = ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test', 'scheduled_at' => inDays(2)];

    crmAct($this, 'approval', [...$base, 'post' => $draft->id, 'scheduled_at' => now()->subHour()->toIso8601String()])->assertUnprocessable();
    crmAct($this, 'approval', [...$base, 'post' => $draft->id, 'scheduled_at' => '2099-01-01T00:00:00Z'])->assertUnprocessable();
    crmAct($this, 'approval', [...$base, 'post' => $draft->id, 'scheduled_at' => null])->assertUnprocessable();
    crmAct($this, 'approval', [...$base, 'post' => $empty->id])->assertStatus(422)->assertJsonPath('error', 'validation');
    crmAct($this, 'approval', [...$base, 'post' => $waiting->id])->assertStatus(409)->assertJsonPath('error', 'not_a_draft');
    crmAct($this, 'approval', [...$base, 'post' => $draft->id, 'actor_email' => 'stan@example.test'])->assertForbidden();
    expect($draft->fresh()->status)->toBe(PostStatus::Draft)->and($empty->fresh()->status)->toBe(PostStatus::Draft);
});
