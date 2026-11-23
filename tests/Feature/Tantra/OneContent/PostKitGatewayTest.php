<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\BoostPlanner;
use App\Tantra\Boost\BoostRun;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Boost\RunBoostAction;
use App\Tantra\Kit\KitEvents;
use App\Tantra\Kit\PostKit;
use App\Tantra\Notify\SendToCrm;
use App\Tantra\Provision\TantraWorkspaceLink;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/GatewayHelpers.php';

// tantra: One Content app PRD 3.8, the post kit (first comment, author reply, team boost entries) and the kit events.

beforeEach(function () {
    Cache::flush();
    oneGatewaySetUp($this);
    config(['tantra.crm.intake_key' => null, 'tantra.crm.url' => 'https://crm.test', 'tantra.boost.enabled' => true,
        'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);

    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    $this->owner->forceFill(['clerk_user_id' => ONE_GW_CLERK])->save();
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_crm_team', 'workspace_id' => $this->ws->id, 'account_id' => $this->owner->account_id, 'name' => 'Team']);
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_adi']);

    // Nelbin has a brand profile of her own; Kim only has a Tantra login.
    $this->nelbin = User::factory()->create(['account_id' => $this->owner->account_id, 'clerk_user_id' => 'user_NelbinTeammate000000001']);
    $nelbinWs = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->nelbin->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $nelbinWs->id, 'brand_profile_key' => 'nelbin', 'review_owner_user_id' => $this->nelbin->id]);
    $this->kim = User::factory()->create(['account_id' => $this->owner->account_id, 'clerk_user_id' => 'user_KimTeammate00000000001']);

    MemberProfile::query()->create(['user_id' => $this->owner->id, 'unipile_account_id' => 'acc_adi', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now()]);
    MemberProfile::query()->create(['user_id' => $this->nelbin->id, 'unipile_account_id' => 'acc_nelbin', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now()]);

    ImportBatch::execute($this->owner, ['engine_batch_id' => '31', 'posts' => [[
        'engine_post_id' => 'KIT1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-12-01', 'time' => '08:30',
        'tz' => 'UTC', 'text' => 'The post.', 'first_comment' => 'Link in here.',
        'engagement_comments' => [
            ['by' => 'nelbin', 'text' => 'Nelbin comment.', 'reply_to' => null],
            ['by' => 'adi', 'text' => 'Author answers Nelbin.', 'reply_to' => 0],
        ],
    ]]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'KIT1')->sole();
    $this->path = "/api/gateway/tantra/posts/{$this->item->id}/thread";
    config(['tantra.crm.intake_key' => 'k']);
});

it('reads the kit with an etag, each entry keyed by its member Clerk user id', function () {
    $r = oneGatewayCall($this, 'GET', $this->path)->assertOk()
        ->assertJsonPath('data.ref', $this->item->id)
        ->assertJsonPath('data.first_comment', 'Link in here.')
        ->assertJsonPath('data.read_only', false)
        ->assertJsonPath('data.entries.0.member', 'user_NelbinTeammate000000001')
        ->assertJsonPath('data.entries.0.kind', 'comment')
        ->assertJsonPath('data.entries.1.member', ONE_GW_CLERK)
        ->assertJsonPath('data.entries.1.reply_to', 0)
        ->assertJsonPath('data.entries.1.status', 'ready');

    expect($r->json('data.etag'))->toBe(PostKit::etag($this->item))
        ->and(trim((string) $r->headers->get('ETag'), '"'))->toBe($r->json('data.etag'));
});

it('needs If-Match and refuses a stale etag with the current kit', function () {
    oneGatewayCall($this, 'PUT', $this->path, ['first_comment' => 'New.'])->assertStatus(428)->assertJsonPath('code', 'etag_required');

    oneGatewayCall($this, 'PUT', $this->path, ['first_comment' => 'New.'], ['If-Match' => '"stale"'])
        ->assertStatus(409)->assertJsonPath('code', 'etag_mismatch')->assertJsonPath('data.first_comment', 'Link in here.');

    expect(data_get($this->item->refresh()->payload, 'first_comment'))->toBe('Link in here.');
});

it('saves the kit, maps members to thread keys, and sends engine.thread_updated', function () {
    Queue::fake([SendToCrm::class]);
    $etag = PostKit::etag($this->item);

    $r = oneGatewayCall($this, 'PUT', $this->path, [
        'first_comment' => 'Grab the guide here.',
        'author_reply' => 'Thanks all, the guide is in the first comment.',
        'entries' => [
            ['member' => 'user_NelbinTeammate000000001', 'text' => 'Nelbin again.'],
            ['member' => 'user_KimTeammate00000000001', 'text' => 'Kim says hi.'],
            ['member' => ONE_GW_CLERK, 'text' => 'Thanks Kim.', 'reply_to' => 1, 'excluded' => true],
        ],
    ], ['If-Match' => "\"{$etag}\""])->assertOk();

    $item = $this->item->refresh();
    expect($r->json('data.etag'))->not->toBe($etag)->toBe(PostKit::etag($item))
        ->and(data_get($item->payload, 'first_comment'))->toBe('Grab the guide here.')
        ->and(data_get($item->payload, 'author_reply'))->toBe('Thanks all, the guide is in the first comment.')
        ->and(array_column(data_get($item->payload, 'engagement_comments'), 'by'))->toBe(['nelbin', 'user:'.$this->kim->id, 'adi'])
        ->and($r->json('data.entries.1.member'))->toBe('user_KimTeammate00000000001')
        ->and($r->json('data.entries.2.excluded'))->toBeTrue()
        ->and($r->json('data.author_reply.text'))->toBe('Thanks all, the guide is in the first comment.');

    // The channel post carries the new first comment.
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();
    expect(data_get($post->meta, 'thread_replies.0.text'))->toBe('Grab the guide here.');

    Queue::assertPushed(SendToCrm::class, fn (SendToCrm $job): bool => $job->event === KitEvents::THREAD_UPDATED
        && $job->userId === (string) $this->owner->id
        && $job->data['notify'] === false
        && $job->data['tantra_team'] === 'ws_crm_team'
        && $job->data['ref'] === (string) $item->id
        && $job->data['review_item_id'] === (string) $item->id
        && $job->data['etag'] === PostKit::etag($item)
        && is_int($job->data['version'])
        && $job->data['kit']['entries'][1]['member'] === 'user_KimTeammate00000000001');
});

it('refuses edits once the post is approved', function () {
    $this->item->forceFill(['review_status' => ReviewStatus::Approved])->save();

    oneGatewayCall($this, 'GET', $this->path)->assertJsonPath('data.read_only', true);
    oneGatewayCall($this, 'PUT', $this->path, ['first_comment' => 'Late.'], ['If-Match' => PostKit::etag($this->item->refresh())])
        ->assertStatus(422)->assertJsonPath('code', 'read_only');
});

it('refuses dashes, unknown members and replies that point nowhere', function () {
    $etag = PostKit::etag($this->item);

    oneGatewayCall($this, 'PUT', $this->path, ['first_comment' => 'One '.json_decode('"\u2014"').' two.'], ['If-Match' => $etag])->assertStatus(422);
    oneGatewayCall($this, 'PUT', $this->path, ['entries' => [['member' => 'user_Unknown00000000000001', 'text' => 'Hi.']]], ['If-Match' => $etag])->assertStatus(422);
    oneGatewayCall($this, 'PUT', $this->path, ['entries' => [['member' => ONE_GW_CLERK, 'text' => 'Hi.', 'reply_to' => 3]]], ['If-Match' => $etag])->assertStatus(422);
});

it('sends engine.boost_action when the boost runner settles an entry, with the skip reason', function () {
    Queue::fake([SendToCrm::class]);
    $post = Post::query()->where('post_group_id', $this->item->post_group_id)->sole();
    $post->forceFill(['status' => PostStatus::Published, 'platform_post_id' => 'urn:li:share:7000000000000000009'])->saveQuietly();
    $run = BoostRun::query()->create(['post_id' => $post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);
    (new BoostPlanner(fn (int $min, int $max): int => $min))->plan($run, CarbonImmutable::now());
    $comment = BoostAction::query()->where('kind', 'comment')->sole();

    config(['tantra.boost.enabled' => false]);
    (new RunBoostAction($comment->id))->handle(app(UnipileClient::class));

    Queue::assertPushed(SendToCrm::class, fn (SendToCrm $job): bool => $job->event === KitEvents::BOOST_ACTION
        && $job->data['tantra_team'] === 'ws_crm_team'
        && $job->data['review_item_id'] === (string) $this->item->id
        && $job->data['post_id'] === (string) $post->id
        && $job->data['action']['kind'] === 'comment'
        && $job->data['action']['entry_index'] === 0
        && $job->data['action']['member'] === 'user_NelbinTeammate000000001'
        && $job->data['action']['status'] === 'skipped'
        && $job->data['action']['reason'] === 'boosting is switched off'
        && $job->data['kit']['entries'][0]['status'] === 'skipped');
});

it('posts the author reply under the first comment from the author account', function () {
    Http::fake([
        'unipile.test/v2/acc_adi/posts/*/comments/cmt_first' => Http::response(['id' => 'rep_author'], 201),
        'unipile.test/v2/*/posts/*' => Http::response(['id' => 'soc_9']),
    ]);
    $payload = $this->item->payload;
    $payload['author_reply'] = 'Thanks for reading.';
    $this->item->forceFill(['payload' => $payload])->save();
    $post = Post::query()->where('post_group_id', $this->item->post_group_id)->sole();
    $post->forceFill(['status' => PostStatus::Published, 'user_id' => $this->owner->id, 'platform_post_id' => 'urn:li:share:7000000000000000010',
        'meta' => [...(array) $post->meta, 'unipile_first_comment_id' => 'cmt_first']])->saveQuietly();
    $run = BoostRun::query()->create(['post_id' => $post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);

    (new BoostPlanner(fn (int $min, int $max): int => $min))->plan($run, CarbonImmutable::now());
    $reply = BoostAction::query()->where('kind', 'author_reply')->sole();
    expect($reply->error)->toBeNull()->and($reply->status)->toBe('queued')->and($reply->unipile_account_id)->toBe('acc_adi');

    (new RunBoostAction($reply->id))->handle(app(UnipileClient::class));

    expect($reply->refresh()->status)->toBe('posted')->and($reply->unipile_ref)->toBe('rep_author');
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/acc_adi/posts/soc_9/comments/cmt_first') && $r['text'] === 'Thanks for reading.');
    oneGatewayCall($this, 'GET', $this->path)->assertJsonPath('data.author_reply.status', 'posted');
});

it('sends engine.post_approved with the review item when Review approves, and engine.post_unapproved when taken back', function () {
    Queue::fake([SendToCrm::class]);

    oneGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/approve")->assertOk();
    Queue::assertPushed(SendToCrm::class, fn (SendToCrm $job): bool => $job->event === 'engine.post_approved'
        && $job->mail === null
        && $job->data['notify'] === false
        && $job->data['decision'] === 'approved'
        && $job->data['review_item_id'] === (string) $this->item->id
        && $job->data['tantra_team'] === 'ws_crm_team'
        && $job->data['by_member'] === ONE_GW_CLERK
        && $job->data['post_ids'] !== []
        && $job->data['approved_at'] !== null);

    oneGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/unapprove")->assertOk();
    Queue::assertPushed(SendToCrm::class, fn (SendToCrm $job): bool => $job->event === 'engine.post_unapproved'
        && $job->data['decision'] === 'unapproved' && $job->data['review_item_id'] === (string) $this->item->id);
});
