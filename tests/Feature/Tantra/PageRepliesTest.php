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
use App\Tantra\Giveaway\GiveawayReplies;
use App\Tantra\Giveaway\GiveawayReply;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

// Spike S6 (2026-11-12): on a Tantra page post the page answers through Zernio; teammates through Unipile.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:30:00', 'UTC')); // Wednesday 11:00 in Kolkata
    config([
        'tantra.boost.enabled' => true, 'tantra.autoreply.enabled' => true,
        'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k',
        'tantra.zernio.key' => 'zk', 'tantra.zernio.url' => 'https://zernio.test/api/v1',
    ]);

    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->tantra = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id, 'name' => 'Tantra']);
    WorkspaceSettings::query()->create(['workspace_id' => $this->tantra->id, 'brand_profile_key' => 'tantra', 'review_owner_user_id' => $this->adi->id, 'timezone' => 'Asia/Kolkata']);
    SocialAccount::factory()->create(['workspace_id' => $this->tantra->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => 'zacc', 'access_token' => 'zernio-managed']);

    $this->aquila = User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'Aquila Eldho']);
    $ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $ws->id, 'brand_profile_key' => 'aquila', 'review_owner_user_id' => $this->aquila->id]);
    MemberProfile::query()->create(['user_id' => $this->aquila->id, 'unipile_account_id' => 'acc_aquila', 'unipile_name' => 'Aquila Eldho', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now()]);

    ImportBatch::execute($this->adi, ['engine_batch_id' => '5', 'posts' => [[
        'engine_post_id' => 'TC9', 'profile' => 'tantra', 'platform' => 'linkedin', 'date' => '2026-11-26', 'time' => '10:30', 'tz' => 'America/New_York',
        'text' => 'Which funnel would you rather run?',
        'resource' => ['name' => 'Stage Owner Map', 'keyword' => 'STAGES'],
        'engagement_comments' => [
            ['by' => 'aquila', 'text' => 'After, for me.', 'reply_to' => null],
            ['by' => 'tantra', 'text' => 'Good pick, Aquila.', 'reply_to' => 0],
        ],
    ]]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'TC9')->sole();
    $this->post = Post::query()->where('post_group_id', $this->item->post_group_id)->sole();
    $this->post->forceFill(['status' => PostStatus::Published, 'published_at' => now()->subHour(), 'platform_url' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7526176630860431360/'])->saveQuietly();
});

it('posts the teammate comment through Unipile and the page reply through Zernio, in that thread', function () {
    Http::fake([
        'unipile.test/v2/acc_aquila/posts/*/comments' => Http::response(['id' => '7526483257580961792'], 201),
        'unipile.test/v2/*/posts/*' => Http::response(['id' => 'soc_9']),
        'zernio.test/api/v1/inbox/comments/*' => Http::response(['success' => true, 'data' => ['commentId' => 'urn:li:comment:(urn:li:ugcPost:7526176630860431360,7526484586453749760)', 'isReply' => true]]),
    ]);

    $run = BoostRun::query()->create(['post_id' => $this->post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);
    (new BoostPlanner(fn (int $min, int $max): int => $min))->plan($run, CarbonImmutable::now());

    $comment = BoostAction::query()->where('kind', 'comment')->sole();
    $reply = BoostAction::query()->where('kind', 'reply')->sole();
    expect($comment->via)->toBe('unipile')->and($reply->via)->toBe('zernio')->and($reply->status)->toBe('queued')
        ->and(BoostAction::query()->where('kind', 'reaction')->pluck('profile_key')->all())->toBe(['aquila']);

    (new RunBoostAction($comment->id))->handle(app(UnipileClient::class));
    (new RunBoostAction($reply->id))->handle(app(UnipileClient::class), app(ZernioClient::class));

    expect($reply->refresh()->status)->toBe('posted');
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'zernio.test/api/v1/inbox/comments/')
        && $r['accountId'] === 'zacc'
        && $r['commentId'] === 'urn:li:comment:(urn:li:ugcPost:7526176630860431360,7526483257580961792)'
        && $r['message'] === 'Good pick, Aquila.');
});

it('answers organic giveaway comments as the page, leaving the page\'s own, answered and team comments alone', function () {
    Http::fake([
        'zernio.test/api/v1/inbox/comments/*' => function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['success' => true, 'data' => ['commentId' => 'urn:li:comment:(x,r1)', 'isReply' => true]]);
            }

            return Http::response(['comments' => [
                ['id' => 'urn:li:comment:(u,1)', 'message' => 'STAGES please', 'from' => ['name' => 'Jane Doe', 'username' => 'janedoe', 'isOwner' => false], 'replies' => []],
                ['id' => 'urn:li:comment:(u,2)', 'message' => 'Register here', 'from' => ['name' => 'Tantra', 'isOwner' => true], 'replies' => []],
                ['id' => 'urn:li:comment:(u,3)', 'message' => 'STAGES', 'from' => ['name' => 'Bob Ray', 'isOwner' => false], 'replies' => [['from' => ['name' => 'Tantra', 'isOwner' => true]]]],
                ['id' => 'urn:li:comment:(u,4)', 'message' => 'After, for me.', 'from' => ['name' => 'Aquila Eldho', 'isOwner' => false], 'replies' => []],
            ]]);
        },
    ]);

    expect(GiveawayReplies::run(app(UnipileClient::class), app(ZernioClient::class)))->toBe(1);

    $reply = GiveawayReply::query()->sole();
    expect($reply->via)->toBe('zernio')
        ->and($reply->comment_id)->toBe('urn:li:comment:(u,1)')
        ->and($reply->status)->toBe('replied')
        ->and($reply->reply_text)->toContain('Jane');

    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && $r['commentId'] === 'urn:li:comment:(u,1)' && $r['accountId'] === 'zacc');
});
