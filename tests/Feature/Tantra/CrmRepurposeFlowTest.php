<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Repurpose\RepostApplier;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's Repurpose automation (tantra-crm docs/crm/requests/ENGINE_REQUEST_2026_11_18_REPURPOSE.md): the full brief on
// start, a profile's recent posts, a repost of a post that is not a review item, and how the jobs are doing. Nothing is published.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    Http::fake();
    $this->owner = User::factory()->create(['email' => 'lead@example.test']);
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'North']);
    $this->unmapped = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Elsewhere']);
    WorkspaceSettings::query()->create(['workspace_id' => $this->team->id, 'brand_profile_key' => 'north', 'review_owner_user_id' => $this->owner->id, 'timezone' => 'UTC']);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'display_name' => 'North on LinkedIn']);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::X, 'display_name' => 'North on X']);
    $this->hidden = SocialAccount::factory()->create(['workspace_id' => $this->unmapped->id, 'platform' => Platform::Bluesky, 'display_name' => 'Elsewhere Bluesky']);
    $stranger = User::factory()->create();
    $this->otherWs = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $this->otherX = SocialAccount::factory()->create(['workspace_id' => $this->otherWs->id, 'platform' => Platform::X, 'display_name' => 'Other on X']);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_north={$this->team->id},ws_other={$this->otherWs->id}",
    ]);
    ImportBatch::execute($this->owner, ['engine_batch_id' => '9', 'posts' => [
        ['engine_post_id' => 'RF1', 'profile' => 'north', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'UTC', 'text' => 'Three ways to plan a quarter.'],
    ]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'RF1')->sole();
});

function crmFlow(object $test, string $path, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))->postJson("/api/tantra/engagement/repurpose/{$path}", $body);
}

function publishedOn(SocialAccount $channel, User $by, string $text, string $at, PostStatus $status = PostStatus::Published): Post
{
    return Post::factory()->create([
        'workspace_id' => $channel->workspace_id, 'user_id' => $by->id, 'social_account_id' => $channel->id, 'platform' => $channel->platform,
        'status' => $status, 'content' => $text, 'published_at' => $status === PostStatus::Published ? $at : null,
        'platform_url' => $status === PostStatus::Published ? 'https://social.example/p/'.md5($text) : null,
    ]);
}

function feedPost(array $over = []): array
{
    return ['key' => 'https://blog.example/posts/plan-a-quarter', 'text' => "How we plan a quarter.\n\nStart with the one number that matters.", 'url' => 'https://blog.example/posts/plan-a-quarter',
        'published_at' => '2026-11-17T09:00:00Z', 'network' => null, 'channel_id' => null, 'kind' => 'feed', ...$over];
}

it('answers 401 without the key on the three new routes', function () {
    crmFlow($this, 'source-posts', ['workspace' => 'ws_north'], null)->assertUnauthorized();
    crmFlow($this, 'start-post', ['workspace' => 'ws_north'], 'nope')->assertUnauthorized();
    crmFlow($this, 'runs', ['workspace' => 'ws_north', 'jobs' => []], null)->assertUnauthorized();
    expect(AgentJob::query()->count())->toBe(0);
});

it('takes the full brief on start in place of the note, and keeps where it came from and the draft only guard', function () {
    $brief = "## Tone\nPlain and warm.\n\n".str_repeat('Keep the numbers. ', 100);
    crmFlow($this, 'start', ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'review_item_id' => $this->item->id,
        'targets' => [['channel_id' => $this->x->id]], 'note' => mb_substr($brief, 0, 1000), 'brief' => $brief, 'origin' => 'repurpose_flow', 'draft_only' => true])
        ->assertCreated()->assertJsonPath('data.kind', 'repost');
    $job = AgentJob::query()->sole();
    expect($job->input['note'])->toBe($brief)->and(mb_strlen($job->input['note']))->toBeGreaterThan(1000)
        ->and($job->input['origin'])->toBe('repurpose_flow')->and($job->input['draft_only'])->toBeTrue();

    // Without a brief the note is used, as before, and nothing extra is stored.
    crmFlow($this, 'start', ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'review_item_id' => $this->item->id,
        'targets' => [['channel_id' => $this->x->id]], 'note' => 'Short.'])->assertCreated();
    $plain = AgentJob::query()->latest('created_at')->latest('id')->get()->first(fn (AgentJob $j): bool => $j->id !== $job->id);
    expect($plain->input['note'])->toBe('Short.')->and($plain->input)->not->toHaveKeys(['origin', 'draft_only']);

    crmFlow($this, 'start', ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'review_item_id' => $this->item->id,
        'targets' => [['channel_id' => $this->x->id]], 'brief' => str_repeat('x', 4001)])->assertUnprocessable();
});

it('lists a team channel\'s published posts after since, newest first, never drafts or another channel\'s, and refuses outside the team', function () {
    publishedOn($this->linkedin, $this->owner, 'Older than since', '2026-11-01 10:00:00');
    publishedOn($this->linkedin, $this->owner, 'Monday note on planning', '2026-11-16 10:00:00');
    publishedOn($this->linkedin, $this->owner, 'Wednesday note on hiring', '2026-11-18 08:00:00');
    publishedOn($this->linkedin, $this->owner, 'Still a draft', '2026-11-18 09:00:00', PostStatus::Draft);
    publishedOn($this->x, $this->owner, 'On the other channel', '2026-11-17 10:00:00');

    $base = ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'channel_id' => $this->linkedin->id, 'since' => '2026-11-10T00:00:00Z', 'limit' => 20];
    $r = crmFlow($this, 'source-posts', $base)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(collect($r->json('data'))->pluck('text')->all())->toBe(['Wednesday note on hiring', 'Monday note on planning'])
        ->and($r->json('data.0'))->toHaveKeys(['id', 'text', 'url', 'published_at'])->and(array_keys($r->json('data.0')))->toHaveCount(4)
        ->and($r->json('data.0.published_at'))->toBe('2026-11-18T08:00:00+00:00')
        ->and($r->json('data.0.url'))->toStartWith('https://social.example/p/');
    // The id is stable per post.
    expect(crmFlow($this, 'source-posts', $base)->json('data.0.id'))->toBe($r->json('data.0.id'));
    crmFlow($this, 'source-posts', [...$base, 'limit' => 1])->assertJsonCount(1, 'data');

    crmFlow($this, 'source-posts', [...$base, 'channel_id' => $this->hidden->id])->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmFlow($this, 'source-posts', [...$base, 'workspace' => 'ws_other'])->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmFlow($this, 'source-posts', [...$base, 'actor_email' => 'nobody@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmFlow($this, 'source-posts', [...$base, 'limit' => 21])->assertUnprocessable();
});

it('queues a repost of a feed post through the same job, with the text given as its source', function () {
    $r = crmFlow($this, 'start-post', ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'targets' => [['channel_id' => $this->x->id]],
        'note' => 'Short.', 'brief' => 'Keep it under 280 characters.', 'origin' => 'repurpose_flow', 'draft_only' => true, 'source' => feedPost()])
        ->assertCreated();
    expect($r->json('data'))->toHaveKeys(['id', 'kind', 'status', 'summary'])->and($r->json('data.kind'))->toBe('repost');
    $job = AgentJob::query()->sole();
    expect($job->workspace_id)->toBe($this->team->id)
        ->and($job->input['source']['text'])->toContain('one number that matters')
        ->and($job->input['source']['review_item_id'])->toBeNull()
        ->and($job->input['source']['external_key'])->toBe('https://blog.example/posts/plan-a-quarter')
        ->and($job->input['source']['kind'])->toBe('feed')
        ->and($job->input['note'])->toBe('Keep it under 280 characters.')
        ->and($job->input['draft_only'])->toBeTrue()
        ->and(collect($job->input['targets'])->pluck('network')->all())->toBe(['x']);

    // A profile's own post: the network comes from the source channel.
    crmFlow($this, 'start-post', ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'targets' => [['channel_id' => $this->x->id]],
        'source' => feedPost(['key' => 'post:abc', 'kind' => 'channel', 'channel_id' => $this->linkedin->id])])->assertCreated();
    expect(AgentJob::query()->get()->firstWhere(fn (AgentJob $j): bool => $j->input['source']['external_key'] === 'post:abc')->input['source']['platform'])->toBe('linkedin');
});

it('refuses a start-post outside the team, from an unknown person, or with a bad source', function () {
    $base = ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'targets' => [['channel_id' => $this->x->id]], 'source' => feedPost()];
    crmFlow($this, 'start-post', [...$base, 'targets' => [['channel_id' => $this->hidden->id]]])->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmFlow($this, 'start-post', [...$base, 'targets' => [['channel_id' => $this->otherX->id]]])->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmFlow($this, 'start-post', [...$base, 'source' => feedPost(['kind' => 'channel', 'channel_id' => $this->hidden->id])])->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmFlow($this, 'start-post', [...$base, 'workspace' => 'ws_unknown'])->assertNotFound();
    crmFlow($this, 'start-post', [...$base, 'actor_email' => 'nobody@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmFlow($this, 'start-post', [...$base, 'source' => feedPost(['kind' => 'email'])])->assertUnprocessable();
    crmFlow($this, 'start-post', [...$base, 'source' => feedPost(['text' => str_repeat('y', 6001)])])->assertUnprocessable();
    crmFlow($this, 'start-post', [...$base, 'targets' => [['network' => 'myspace']]])->assertUnprocessable();
    expect(AgentJob::query()->count())->toBe(0);
});

it('says how the team\'s jobs are doing, with the draft each made in Review, and never another team\'s', function () {
    $id = crmFlow($this, 'start-post', ['workspace' => 'ws_north', 'actor_email' => 'lead@example.test', 'targets' => [['channel_id' => $this->x->id]],
        'draft_only' => true, 'source' => feedPost()])->assertCreated()->json('data.id');
    $job = AgentJob::query()->findOrFail($id);

    $r = crmFlow($this, 'runs', ['workspace' => 'ws_north', 'jobs' => [$id, 'not-a-job']])->assertOk();
    expect($r->json('data'))->toHaveCount(1)->and($r->json('data.0.status'))->toBeIn(['waiting', 'working'])->and($r->json('data.0.items'))->toBe([]);

    // The agent's result lands as a draft in Review, never approved, scheduled or published.
    $target = $job->input['targets'][0]['target'];
    $summary = app(RepostApplier::class)->apply($job, ['items' => [['target' => $target, 'text' => 'One number per quarter. That is the plan.']]]);
    $job->forceFill(['status' => AgentJob::DONE, 'summary' => $summary])->save();
    $review = ReviewItem::query()->where('id', '!=', $this->item->id)->sole();
    expect($review->review_status)->toBe(ReviewStatus::InReview)->and($review->workspace_id)->toBe($this->team->id)
        ->and(Post::query()->whereIn('status', [PostStatus::Scheduled, PostStatus::Published, PostStatus::Publishing])->count())->toBe(0);

    $r = crmFlow($this, 'runs', ['workspace' => 'ws_north', 'jobs' => [$id]])->assertOk();
    expect($r->json('data.0'))->toMatchArray(['id' => $id, 'status' => 'done'])
        ->and($r->json('data.0.items'))->toBe([['network' => 'x', 'text' => 'One number per quarter. That is the plan.', 'status' => 'in_review']]);

    crmFlow($this, 'runs', ['workspace' => 'ws_other', 'jobs' => [$id]])->assertOk()->assertJsonCount(0, 'data');
    crmFlow($this, 'runs', ['workspace' => 'ws_north', 'jobs' => array_fill(0, 51, $id)])->assertUnprocessable();

    $job->forceFill(['status' => AgentJob::CANCELLED])->save();
    expect(crmFlow($this, 'runs', ['workspace' => 'ws_north', 'jobs' => [$id]])->json('data.0.status'))->toBe('failed');
});
