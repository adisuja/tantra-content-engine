<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Tantra\Notify\Mirror\ContentChange;
use App\Tantra\Notify\Mirror\SendContentChange;
use App\Tantra\Notify\Mirror\Versions;
use App\Tantra\Ops\LeanHorizon;
use App\Tantra\Ops\StubPublisher;
use App\Tantra\Provision\TantraWorkspaceLink;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Speed P3 (tantra-crm PRD_2026_11_21_SPEED_AND_STABILITY section 10, B1 to B3): every Content change goes to the CRM's mirror after commit,
// on its own queue, with the Tantra team id, the ref, a version that only goes up, and the kind. Nothing reaches a network or a real CRM.
beforeEach(function () {
    config([
        'tantra.crm.url' => 'https://crm.test', 'tantra.crm.intake_key' => 'k', 'tantra.crm.mirror.enabled' => true,
        'tantra.engagement_channels.workspaces' => '',
    ]);
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ws = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Ledgerline QA']);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_qa_team', 'workspace_id' => $this->ws->id, 'account_id' => $this->owner->account_id, 'name' => 'Ledgerline QA']);
    $this->other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Not mapped']);
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn, 'display_name' => 'QA Page']);
    // Jobs are only looked at or run by hand below: nothing is sent while a test sets up.
    Queue::fake();
});

function mirrorJobs(): Collection
{
    return Queue::pushed(SendContentChange::class)->values();
}

it('queues post.created after commit on the low priority mirror queue, with the team id', function () {
    Queue::fake();
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id, 'status' => PostStatus::Draft]);

    Queue::assertPushedOn('tantra-mirror', SendContentChange::class, fn (SendContentChange $j) => $j->kind === ContentChange::POST_CREATED
        && $j->team === 'ws_qa_team' && $j->subjectId === (string) $post->id && $j->workspaceId === (string) $this->ws->id);
});

it('sends nothing for a workspace mapped to no Tantra team (B1)', function () {
    Queue::fake();
    $account = SocialAccount::factory()->create(['workspace_id' => $this->other->id, 'platform' => Platform::LinkedIn]);
    Post::factory()->create(['workspace_id' => $this->other->id, 'social_account_id' => $account->id]);

    Queue::assertNotPushed(SendContentChange::class);
});

it('maps through ENGINE_CHANNELS_WORKSPACES when there is no provision link, and never when two teams claim one workspace', function () {
    Queue::fake();
    config(['tantra.engagement_channels.workspaces' => "ws_env_team={$this->other->id}"]);
    $account = SocialAccount::factory()->create(['workspace_id' => $this->other->id, 'platform' => Platform::LinkedIn]);
    Queue::assertPushed(SendContentChange::class, fn (SendContentChange $j) => $j->team === 'ws_env_team' && $j->subjectId === (string) $account->id);

    Queue::fake();
    $third = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    config(['tantra.engagement_channels.workspaces' => "ws_a={$third->id},ws_b={$third->id}"]);
    SocialAccount::factory()->create(['workspace_id' => $third->id, 'platform' => Platform::LinkedIn]);
    Queue::assertNotPushed(SendContentChange::class);
});

it('sends nothing when the save is rolled back', function () {
    Queue::fake();
    try {
        DB::transaction(function () {
            Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id]);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(SendContentChange::class);
});

it('names the kind of each post change: scheduled, published, failed, updated, deleted', function () {
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id, 'status' => PostStatus::Draft]);
    Queue::fake();

    $post->update(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
    $post->update(['content' => 'New words']);
    $post->update(['status' => PostStatus::Published, 'published_at' => now()]);
    $post->update(['status' => PostStatus::Failed]);
    $post->forceFill(['deleted_at' => now()])->save();

    expect(mirrorJobs()->pluck('kind')->all())->toBe([
        ContentChange::POST_SCHEDULED, ContentChange::POST_UPDATED, ContentChange::POST_PUBLISHED, ContentChange::POST_FAILED, ContentChange::POST_DELETED,
    ]);
});

it('ignores a save that changes nothing the Content screens show', function () {
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id]);
    Queue::fake();
    $post->forceFill(['updated_at' => now()->addMinute()])->save();

    Queue::assertNotPushed(SendContentChange::class);
});

it('posts the current post, the ref, the kind and a version that only goes up', function () {
    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true], 202)]);
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id, 'status' => PostStatus::Draft, 'content' => 'First']);
    $job = new SendContentChange('ws_qa_team', (string) $this->ws->id, ContentChange::POST_UPDATED, ContentChange::SUBJECT_POST, (string) $post->id);

    $job->handle();
    $job->handle();

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
    expect($sent)->toHaveCount(2)
        ->and($sent[0]['event'])->toBe('engine.content_changed')
        ->and($sent[0]['tantra_team'])->toBe('ws_qa_team')
        ->and($sent[0]['kind'])->toBe('post.updated')
        ->and($sent[0]['ref'])->toBe((string) $post->id)
        ->and($sent[0]['data']['post']['id'])->toBe((string) $post->id)
        ->and($sent[0]['data']['post']['content'])->toBe('First')
        ->and($sent[0]['version'])->toBeGreaterThanOrEqual($post->updated_at->getTimestampMs())
        ->and($sent[1]['version'])->toBeGreaterThan($sent[0]['version'])
        ->and($sent[0])->not->toHaveKey('recipient');
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer k'));
});

it('sends post.deleted when the post is gone by the time the job runs', function () {
    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true], 202)]);
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id]);
    $post->forceFill(['deleted_at' => now()])->saveQuietly();

    (new SendContentChange('ws_qa_team', (string) $this->ws->id, ContentChange::POST_UPDATED, ContentChange::SUBJECT_POST, (string) $post->id))->handle();

    Http::assertSent(fn ($r) => $r['kind'] === 'post.deleted' && $r['ref'] === (string) $post->id && $r['data'] === ['removed' => true]);
});

it('gives up on a refusal (4xx) and retries a CRM error (5xx)', function () {
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id]);
    $make = fn () => new SendContentChange('ws_qa_team', (string) $this->ws->id, ContentChange::POST_UPDATED, ContentChange::SUBJECT_POST, (string) $post->id);

    Http::fake(['crm.test/intake/engine' => Http::sequence()->push(['error' => 'unknown team'], 422)->push(['error' => 'down'], 503)]);
    $refused = $make()->withFakeQueueInteractions();
    $refused->handle();
    $refused->assertFailed();

    expect(fn () => $make()->handle())->toThrow(RuntimeException::class);
});

it('keeps versions monotonic per thing even when the clock says otherwise', function () {
    $a = Versions::next('post:x', 5_000);
    $b = Versions::next('post:x', 1_000);
    $c = Versions::next('post:x', 9_000);
    $other = Versions::next('post:y', 10);

    expect($b)->toBe($a + 1)->and($c)->toBe(9_000)->and($other)->toBe(10);
});

it('sends review.changed when an item under review changes', function () {
    config(['tantra.crm.mirror.enabled' => false]);
    WorkspaceSettings::query()->updateOrCreate(['workspace_id' => $this->ws->id], ['brand_profile_key' => 'qa', 'review_owner_user_id' => $this->owner->id]);
    $this->owner->forceFill(['current_workspace_id' => $this->ws->id])->save();
    ImportBatch::execute($this->owner, ['engine_batch_id' => '7', 'posts' => [[
        'engine_post_id' => 'QA1', 'profile' => 'qa', 'platform' => 'linkedin', 'date' => '2026-11-30', 'time' => '09:00', 'tz' => 'UTC', 'text' => 'A post for review',
    ]]]);
    $item = ReviewItem::query()->where('engine_post_id', 'QA1')->sole();
    config(['tantra.crm.mirror.enabled' => true]);
    Queue::fake();

    $item->update(['review_status' => ReviewStatus::Approved]);

    Queue::assertPushedOn('tantra-mirror', SendContentChange::class, fn (SendContentChange $j) => $j->kind === ContentChange::REVIEW_CHANGED && $j->subjectId === (string) $item->id);

    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true], 202)]);
    (new SendContentChange('ws_qa_team', (string) $this->ws->id, ContentChange::REVIEW_CHANGED, ContentChange::SUBJECT_REVIEW, (string) $item->id))->handle();
    Http::assertSent(fn ($r) => $r['kind'] === 'review.changed' && $r['ref'] === (string) $item->id && $r['data']['review']['status'] === ReviewStatus::Approved->value);
});

it('sends channel.connected and channel.disconnected with the channel as the channels list shows it', function () {
    Queue::fake();
    $this->account->update(['status' => AccountStatus::Disconnected]);
    Queue::assertPushed(SendContentChange::class, fn (SendContentChange $j) => $j->subject === ContentChange::SUBJECT_CHANNEL && $j->subjectId === (string) $this->account->id);

    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true], 202)]);
    (new SendContentChange('ws_qa_team', (string) $this->ws->id, ContentChange::CHANNEL_CONNECTED, ContentChange::SUBJECT_CHANNEL, (string) $this->account->id))->handle();
    Http::assertSent(fn ($r) => $r['kind'] === 'channel.disconnected' && $r['data']['channel']['id'] === (string) $this->account->id
        && $r['data']['channel']['connected'] === false && $r['data']['channel']['name'] === 'QA Page');

    $id = (string) $this->account->id;
    $this->account->delete();
    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true], 202)]);
    (new SendContentChange('ws_qa_team', (string) $this->ws->id, ContentChange::CHANNEL_DISCONNECTED, ContentChange::SUBJECT_CHANNEL, $id))->handle();
    Http::assertSent(fn ($r) => $r['kind'] === 'channel.disconnected' && $r['data'] === ['removed' => true]);
});

it('sends one delayed metrics.read per workspace for a burst of metric rows', function () {
    Queue::fake();
    AnalyticsAccountDailySnapshot::factory()->count(3)->sequence(fn ($s) => ['date' => now()->subDays($s->index + 1)->toDateString()])
        ->create(['workspace_id' => $this->ws->id]);

    $jobs = Queue::pushed(SendContentChange::class)->filter(fn (SendContentChange $j) => $j->kind === ContentChange::METRICS_READ)->values();
    expect($jobs)->toHaveCount(1)->and($jobs[0]->team)->toBe('ws_qa_team')->and($jobs[0]->delay)->not->toBeNull();
});

it('runs the mirror on its own Horizon worker, never the publishing pool', function () {
    $mirror = config('horizon.defaults.tantra-mirror');
    expect($mirror['queue'])->toBe(['tantra-mirror'])
        ->and(config('horizon.defaults.social-publishing.queue'))->not->toContain('tantra-mirror');

    $plan = LeanHorizon::plan((array) config('horizon.defaults'), ['social-linkedin'], 3);
    expect($plan)->toHaveKey('tantra-mirror')->and($plan[LeanHorizon::SUPERVISOR]['queue'])->not->toContain('tantra-mirror');
});

it('only stubs publishing when switched on, and never in production', function () {
    config(['tantra.publish_stub.enabled' => false]);
    expect(StubPublisher::enabled())->toBeFalse();

    config(['tantra.publish_stub.enabled' => true, 'tantra.publish_stub.latency_ms' => 0]);
    expect(StubPublisher::enabled())->toBeTrue();
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id]);
    expect((new StubPublisher)->publish($post)['id'])->toStartWith('stub_');

    app()->detectEnvironment(fn () => 'production');
    expect(StubPublisher::enabled())->toBeFalse();
    app()->detectEnvironment(fn () => 'testing');
});
