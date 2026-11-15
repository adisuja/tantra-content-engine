<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's Content > Drafts and Calendar read posts server to server (POST /api/tantra/engagement/posts).

beforeEach(function () {
    $owner = User::factory()->create();
    $this->owner = $owner;
    $this->team = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $stranger = User::factory()->create();
    $this->strangerUser = $stranger;
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adi Murali']);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id},ws_other={$this->other->id}",
    ]);
});

function crmPost(object $test, string $content, array $state = [], ?Workspace $workspace = null): Post
{
    return Post::factory()->forAccount($test->linkedin)->create([
        'workspace_id' => ($workspace ?? $test->team)->id, 'user_id' => $test->owner->id, 'content' => $content, ...$state,
    ]);
}

function crmPosts(object $test, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))
        ->postJson('/api/tantra/engagement/posts', $body);
}

it('answers 401 to a missing, wrong or unset key', function () {
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft']], null)->assertUnauthorized();
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft']], 'nope')->assertUnauthorized();
    config(['tantra.engagement_channels.key' => null]);
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft']], '')->assertUnauthorized();
});

it('lists the drafts and posts waiting for approval of the mapped team, with the fields a person reads, and no ids of the Engine', function () {
    crmPost($this, 'A draft about timing.', ['status' => PostStatus::Draft]);
    crmPost($this, 'Waiting for a yes.', ['status' => PostStatus::PendingApproval, 'scheduled_at' => now()->addDay()]);
    crmPost($this, 'Already scheduled.', ['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(2)]);
    crmPost($this, 'Their draft.', ['status' => PostStatus::Draft], $this->other);

    $response = crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft', 'pending_approval']])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $rows = collect($response->json('data'));
    expect($rows->pluck('content')->all())->toEqualCanonicalizing(['A draft about timing.', 'Waiting for a yes.'])
        ->and($rows->pluck('status')->unique()->sort()->values()->all())->toBe(['draft', 'pending_approval']);
    $draft = $rows->firstWhere('content', 'A draft about timing.');
    expect($draft)->toHaveKeys(['id', 'status', 'content', 'platform', 'content_type', 'scheduled_at', 'published_at', 'display_name', 'display_username', 'platform_url', 'error_message', 'labels', 'updated_at'])
        ->and($draft['platform'])->toBe('linkedin')->and($draft['display_name'])->toBe('Adi Murali')
        ->and($draft)->not->toHaveKeys(['workspace_id', 'user_id', 'author', 'post_group_id', 'social_account_id']);
    $response->assertJsonPath('meta.total', 2);
});

it('cuts the Calendar window by published_at, else scheduled_at, both ends inclusive', function () {
    $monday = now()->startOfWeek()->addDays(7)->setTime(9, 30);
    crmPost($this, 'Monday morning.', ['status' => PostStatus::Scheduled, 'scheduled_at' => $monday]);
    crmPost($this, 'Out on Wednesday.', ['status' => PostStatus::Published, 'scheduled_at' => $monday->copy()->addDays(2)->subHour(), 'published_at' => $monday->copy()->addDays(2)]);
    crmPost($this, 'A failure.', ['status' => PostStatus::Failed, 'scheduled_at' => $monday->copy()->addDays(4), 'error_message' => 'Token expired']);
    crmPost($this, 'Next week.', ['status' => PostStatus::Scheduled, 'scheduled_at' => $monday->copy()->addDays(9)]);
    crmPost($this, 'A draft with no time.', ['status' => PostStatus::Draft]);
    $statuses = ['scheduled', 'publishing', 'published', 'failed'];
    $from = $monday->copy()->startOfDay()->toIso8601String();
    $to = $monday->copy()->addDays(6)->endOfDay()->toIso8601String();

    $week = crmPosts($this, ['workspace' => 'ws_team', 'statuses' => $statuses, 'from' => $from, 'to' => $to])->assertOk();
    expect(collect($week->json('data'))->pluck('content')->all())->toEqualCanonicalizing(['Monday morning.', 'Out on Wednesday.', 'A failure.']);
    $failed = collect($week->json('data'))->firstWhere('content', 'A failure.');
    expect($failed['error_message'])->toBe('Token expired')->and($failed['status'])->toBe('failed');
    // The window is inclusive at both ends.
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => $statuses, 'from' => $monday->toIso8601String(), 'to' => $monday->toIso8601String()])->assertJsonCount(1, 'data');
    // No window: every post of those statuses.
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => $statuses])->assertJsonCount(4, 'data');
});

it('gives an unknown or unmapped team an empty list, never another team\'s posts', function () {
    crmPost($this, 'Mine.', ['status' => PostStatus::Draft]);
    crmPosts($this, ['workspace' => 'ws_unknown', 'statuses' => ['draft']])->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    crmPosts($this, ['workspace' => 'ws_other', 'statuses' => ['draft']])->assertOk()->assertJsonCount(0, 'data');
});

it('validates the request and pages the list', function () {
    foreach (range(1, 5) as $n) {
        crmPost($this, "Draft {$n}.", ['status' => PostStatus::Draft]);
    }
    crmPosts($this, ['statuses' => ['draft']])->assertUnprocessable();
    crmPosts($this, ['workspace' => 'ws_team'])->assertUnprocessable();
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['nonsense']])->assertUnprocessable();
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft'], 'from' => 'not a date'])->assertUnprocessable();
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft'], 'per_page' => 2, 'page' => 3])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 3);
    crmPosts($this, ['workspace' => 'ws_team', 'statuses' => ['draft'], 'per_page' => 500])->assertOk()->assertJsonPath('meta.per_page', 100);
});
