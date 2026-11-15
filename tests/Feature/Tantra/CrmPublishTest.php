<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's Content > Publish: the team's channels by name, and scheduling or publishing a post through CreatePosts as the person.
// Nothing reaches a network: the publish job is faked.

beforeEach(function () {
    Notification::fake();
    Bus::fake([PublishPost::class]);
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    $this->second = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Aquila']);
    $this->direct = workspaceMember($this->team, 'member', ['email' => 'dana@example.test']);
    $this->asker = workspaceMember($this->team, 'approval', ['email' => 'ann@example.test']);
    $stranger = User::factory()->create(['email' => 'stan@example.test']);
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adi Murali', 'status' => Status::Connected]);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::X, 'display_name' => 'Tantra on X', 'status' => Status::Connected]);
    $this->broken = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::Bluesky, 'display_name' => 'Old Bluesky', 'status' => Status::Disconnected]);
    $this->aquila = SocialAccount::factory()->create(['workspace_id' => $this->second->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Aquila page', 'status' => Status::Connected]);
    $this->theirs = SocialAccount::factory()->create(['workspace_id' => $this->other->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Not ours', 'status' => Status::Connected]);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id}|{$this->second->id},ws_other={$this->other->id}",
    ]);
});

function crmPub(object $test, string $path, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))->postJson("/api/tantra/engagement/{$path}", $body);
}

function crmPubBody(object $test, array $over = []): array
{
    return ['workspace' => 'ws_team', 'actor_email' => 'dana@example.test', 'channels' => [$test->linkedin->id, $test->x->id], 'content' => 'We just opened the December webinar.', 'mode' => 'schedule',
        'scheduled_at' => now()->addDays(2)->setTime(9, 30)->utc()->toIso8601String(), ...$over];
}

it('answers 401 to a missing or wrong key on both', function () {
    crmPub($this, 'publish/channels', ['workspace' => 'ws_team'], null)->assertUnauthorized();
    crmPub($this, 'publish', crmPubBody($this), 'nope')->assertUnauthorized();
    expect(Post::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('lists the channels of the team by name, with whether each is connected, and nothing else of them', function () {
    $response = crmPub($this, 'publish/channels', ['workspace' => 'ws_team'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $rows = collect($response->json('data'));
    expect($rows->pluck('name')->all())->toEqualCanonicalizing(['Adi Murali', 'Tantra on X', 'Old Bluesky', 'Aquila page'])
        ->and($rows->firstWhere('name', 'Adi Murali'))->toMatchArray(['network' => 'linkedin', 'platform' => 'linkedin', 'workspace' => 'Tantra', 'connected' => true])
        ->and($rows->firstWhere('name', 'Old Bluesky')['connected'])->toBeFalse()
        ->and($rows->first())->not->toHaveKeys(['access_token', 'refresh_token', 'workspace_id', 'meta']);
    crmPub($this, 'publish/channels', ['workspace' => 'ws_unknown'])->assertOk()->assertJsonPath('data', []);
    expect(collect(crmPub($this, 'publish/channels', ['workspace' => 'ws_other'])->json('data'))->pluck('name')->all())->toBe(['Not ours']);
});

it('schedules one post to every channel picked, as the person, in one group', function () {
    $when = now()->addDays(2)->setTime(9, 30)->utc();
    $r = crmPub($this, 'publish', crmPubBody($this, ['scheduled_at' => $when->toIso8601String()]))->assertCreated()->assertJsonPath('approval', false);
    $posts = Post::query()->where('workspace_id', $this->team->id)->get();
    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('status')->unique()->all())->toBe([PostStatus::Scheduled])
        ->and($posts->pluck('post_group_id')->unique())->toHaveCount(1)
        ->and($posts->pluck('content')->unique()->all())->toBe(['We just opened the December webinar.'])
        ->and($posts->first()->scheduled_at->utc()->toIso8601String())->toBe($when->toIso8601String())
        ->and($posts->pluck('user_id')->unique()->all())->toBe([$this->direct->id]);
    expect(collect($r->json('data'))->pluck('status')->unique()->all())->toBe(['scheduled']);
    Bus::assertNothingDispatched(); // scheduled, not published
});

it('publishes now through the Engine\'s own job, once per channel', function () {
    crmPub($this, 'publish', crmPubBody($this, ['mode' => 'now', 'scheduled_at' => null]))->assertCreated()->assertJsonPath('approval', false);
    expect(Post::query()->where('workspace_id', $this->team->id)->pluck('status')->unique()->all())->toBe([PostStatus::Publishing]);
    Bus::assertDispatchedTimes(PublishPost::class, 2);
});

it('keeps the approval rule: a person whose posts need approval never publishes or schedules directly', function () {
    $r = crmPub($this, 'publish', crmPubBody($this, ['actor_email' => 'ann@example.test']))->assertCreated()->assertJsonPath('approval', true);
    expect(collect($r->json('data'))->pluck('status')->unique()->all())->toBe(['pending_approval']);
    crmPub($this, 'publish', crmPubBody($this, ['actor_email' => 'ann@example.test', 'mode' => 'now', 'scheduled_at' => null, 'content' => 'Another one.']))->assertCreated()->assertJsonPath('approval', true);
    expect(Post::query()->where('workspace_id', $this->team->id)->pluck('status')->unique()->all())->toBe([PostStatus::PendingApproval]);
    Bus::assertNothingDispatched();
});

it('refuses a channel of another team, a channel that is not connected, and channels of two workspaces, and creates nothing', function () {
    crmPub($this, 'publish', crmPubBody($this, ['channels' => [$this->theirs->id]]))->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmPub($this, 'publish', crmPubBody($this, ['channels' => [$this->linkedin->id, $this->theirs->id]]))->assertNotFound();
    crmPub($this, 'publish', crmPubBody($this, ['channels' => [$this->linkedin->id, $this->broken->id]]))->assertUnprocessable()->assertJsonPath('error', 'channel_not_connected');
    crmPub($this, 'publish', crmPubBody($this, ['channels' => [$this->linkedin->id, $this->aquila->id]]))->assertUnprocessable()->assertJsonPath('error', 'one_workspace');
    crmPub($this, 'publish', crmPubBody($this, ['workspace' => 'ws_other']))->assertNotFound();
    crmPub($this, 'publish', crmPubBody($this, ['workspace' => 'ws_unknown']))->assertNotFound();
    expect(Post::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('refuses an unknown person, empty text, a missing or past time, and too many channels', function () {
    crmPub($this, 'publish', crmPubBody($this, ['actor_email' => 'stan@example.test']))->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmPub($this, 'publish', crmPubBody($this, ['content' => '   ']))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['content' => '']))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['scheduled_at' => null]))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['scheduled_at' => now()->subHour()->toIso8601String()]))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['mode' => 'tomorrow']))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['channels' => []]))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['channels' => array_fill(0, 11, (string) str()->uuid())]))->assertUnprocessable();
    crmPub($this, 'publish', crmPubBody($this, ['channels' => [$this->linkedin->id, $this->linkedin->id]]))->assertUnprocessable();
    expect(Post::query()->count())->toBe(0);
});

it('never publishes the same thing twice: the same key, or the same words to the same channels, is refused', function () {
    crmPub($this, 'publish', crmPubBody($this, ['mode' => 'now', 'scheduled_at' => null, 'client_key' => 'dialog-1']))->assertCreated();
    crmPub($this, 'publish', crmPubBody($this, ['mode' => 'now', 'scheduled_at' => null, 'client_key' => 'dialog-1']))->assertStatus(409)->assertJsonPath('error', 'duplicate');
    crmPub($this, 'publish', crmPubBody($this, ['mode' => 'now', 'scheduled_at' => null, 'client_key' => 'dialog-2', 'content' => 'A different post.']))->assertCreated();
    // No key: the same words, channels and time are the same post.
    $body = crmPubBody($this, ['content' => 'Same words, no key.']);
    crmPub($this, 'publish', $body)->assertCreated();
    crmPub($this, 'publish', $body)->assertStatus(409)->assertJsonPath('error', 'duplicate');
    expect(Post::query()->where('content', 'A different post.')->count())->toBe(2)->and(Post::query()->where('content', 'We just opened the December webinar.')->count())->toBe(2);
});

it('lets a post the Engine refuses be tried again with the same key', function () {
    // Over X's length: CreatePosts refuses it, the key is freed, and the same dialog can send a shorter text.
    $long = str_repeat('word ', 90);
    crmPub($this, 'publish', crmPubBody($this, ['client_key' => 'dialog-9', 'content' => $long]))->assertUnprocessable()->assertJsonPath('error', 'validation');
    expect(Post::query()->count())->toBe(0);
    crmPub($this, 'publish', crmPubBody($this, ['client_key' => 'dialog-9', 'content' => 'Short now.']))->assertCreated();
    expect(Post::query()->count())->toBe(2);
});
