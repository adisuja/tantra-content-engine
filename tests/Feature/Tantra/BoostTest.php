<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\BoostPlanner;
use App\Tantra\Boost\BoostRun;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Boost\RunBoostAction;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'tantra.boost.enabled' => true,
        'tantra.unipile.dsn' => 'https://unipile.test/v2',
        'tantra.unipile.key' => 'k',
    ]);

    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->adiWs = $result['workspace'];
    $this->nelbin = User::factory()->create(['account_id' => $this->adi->account_id]);
    $this->nelbinWs = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->nelbin->id]);
    $this->aquila = User::factory()->create(['account_id' => $this->adi->account_id]);
    $this->aquilaWs = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->aquila->id]);

    foreach ([[$this->adiWs, 'adi', $this->adi], [$this->nelbinWs, 'nelbin', $this->nelbin], [$this->aquilaWs, 'aquila', $this->aquila]] as [$ws, $key, $user]) {
        WorkspaceSettings::query()->create(['workspace_id' => $ws->id, 'brand_profile_key' => $key, 'review_owner_user_id' => $user->id]);
    }
    SocialAccount::factory()->create(['workspace_id' => $this->nelbinWs->id, 'platform' => Platform::LinkedIn]);

    // Adi and Nelbin can boost; Aquila never gave consent.
    MemberProfile::query()->create(['user_id' => $this->adi->id, 'unipile_account_id' => 'acc_adi', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now()]);
    MemberProfile::query()->create(['user_id' => $this->nelbin->id, 'unipile_account_id' => 'acc_nelbin', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now()]);
    MemberProfile::query()->create(['user_id' => $this->aquila->id, 'unipile_account_id' => 'acc_aquila', 'unipile_confirmed_at' => now()]);

    ImportBatch::execute($this->adi, ['engine_batch_id' => '4', 'posts' => [[
        'engine_post_id' => 'NA1', 'profile' => 'nelbin', 'platform' => 'linkedin', 'date' => '2026-11-16', 'time' => '08:30',
        'tz' => 'America/New_York', 'text' => 'Post.',
        'engagement_comments' => [
            ['by' => 'adi', 'text' => 'Adi comment.', 'reply_to' => null],
            ['by' => 'aquila', 'text' => 'Aquila comment.', 'reply_to' => null],
            ['by' => 'nelbin', 'text' => 'Author answers Adi.', 'reply_to' => 0],
            ['by' => 'nelbin', 'text' => 'Author answers Aquila.', 'reply_to' => 1],
        ],
    ]]]);

    $this->item = ReviewItem::query()->where('engine_post_id', 'NA1')->sole();
    $this->post = Post::query()->where('post_group_id', $this->item->post_group_id)->sole();
    $this->post->forceFill(['status' => PostStatus::Published, 'platform_post_id' => 'urn:li:share:7000000000000000001'])->saveQuietly();
});

it('plans the thread as written: comments in the window, reactions first, replies after their comment', function () {
    $run = BoostRun::query()->create(['post_id' => $this->post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);
    $start = CarbonImmutable::parse('2026-11-16 13:30:00', 'UTC');

    $actions = collect((new BoostPlanner(fn (int $min, int $max): int => intdiv($min + $max, 2)))->plan($run, $start));

    $adiComment = $actions->first(fn (BoostAction $a) => $a->kind === 'comment' && $a->profile_key === 'adi');
    $adiReaction = $actions->first(fn (BoostAction $a) => $a->kind === 'reaction' && $a->profile_key === 'adi');
    $replyToAdi = $actions->first(fn (BoostAction $a) => $a->kind === 'reply' && $a->entry_index === 2);
    $aquilaComment = $actions->first(fn (BoostAction $a) => $a->kind === 'comment' && $a->profile_key === 'aquila');
    $replyToAquila = $actions->first(fn (BoostAction $a) => $a->kind === 'reply' && $a->entry_index === 3);

    expect($adiComment->status)->toBe('queued')
        ->and($adiComment->offset_seconds)->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(180)
        ->and($adiReaction->offset_seconds)->toBeLessThan($adiComment->offset_seconds)
        ->and($replyToAdi->parent_action_id)->toBe($adiComment->id)
        ->and($replyToAdi->unipile_account_id)->toBe('acc_nelbin')
        ->and($replyToAdi->offset_seconds - $adiComment->offset_seconds)->toBeGreaterThanOrEqual(120)
        ->and($aquilaComment->status)->toBe('skipped')
        ->and($aquilaComment->error)->toBe('no consent to post in their name')
        ->and($replyToAquila->status)->toBe('skipped')
        ->and($actions->where('kind', 'reaction')->pluck('profile_key')->all())->toBe(['adi']);
});

it('posts a reply in its comment thread once the comment is posted', function () {
    Http::fake([
        'unipile.test/v2/acc_adi/posts/*/comments' => Http::response(['id' => 'cmt_1'], 201),
        'unipile.test/v2/acc_nelbin/posts/*/comments/cmt_1' => Http::response(['id' => 'rep_1'], 201),
        'unipile.test/v2/*/posts/*' => Http::response(['id' => 'soc_1']),
    ]);

    $run = BoostRun::query()->create(['post_id' => $this->post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);
    (new BoostPlanner(fn (int $min, int $max): int => $min))->plan($run, CarbonImmutable::now());

    $comment = BoostAction::query()->where('kind', 'comment')->where('profile_key', 'adi')->sole();
    $reply = BoostAction::query()->where('kind', 'reply')->where('entry_index', 2)->sole();

    (new RunBoostAction($comment->id))->handle(app(App\Tantra\Unipile\UnipileClient::class));
    (new RunBoostAction($reply->id))->handle(app(App\Tantra\Unipile\UnipileClient::class));

    expect($comment->refresh()->status)->toBe('posted')
        ->and($comment->unipile_ref)->toBe('cmt_1')
        ->and($reply->refresh()->status)->toBe('posted')
        ->and($reply->unipile_ref)->toBe('rep_1')
        ->and($run->refresh()->unipile_post_ref)->toBe('soc_1');

    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/acc_nelbin/posts/soc_1/comments/cmt_1') && $r['text'] === 'Author answers Adi.');
});

it('pauses an account for 72 hours when LinkedIn restricts it', function () {
    Http::fake([
        'unipile.test/v2/acc_adi/posts/*/comments' => Http::response(['detail' => 'Account restricted by LinkedIn'], 403),
        'unipile.test/v2/*/posts/*' => Http::response(['id' => 'soc_1']),
    ]);

    $run = BoostRun::query()->create(['post_id' => $this->post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);
    (new BoostPlanner(fn (int $min, int $max): int => $min))->plan($run, CarbonImmutable::now());
    $comment = BoostAction::query()->where('kind', 'comment')->where('profile_key', 'adi')->sole();

    (new RunBoostAction($comment->id))->handle(app(App\Tantra\Unipile\UnipileClient::class));

    expect($comment->refresh()->status)->toBe('failed')
        ->and(MemberProfile::query()->find($this->adi->id)->boost_paused_until->isFuture())->toBeTrue();
});

it('posts nothing while boosting is switched off', function () {
    config(['tantra.boost.enabled' => false]);
    Http::fake();

    $run = BoostRun::query()->create(['post_id' => $this->post->id, 'review_item_id' => $this->item->id, 'status' => 'running']);
    (new BoostPlanner)->plan($run, CarbonImmutable::now());
    $comment = BoostAction::query()->where('kind', 'comment')->where('profile_key', 'adi')->sole();

    (new RunBoostAction($comment->id))->handle(app(App\Tantra\Unipile\UnipileClient::class));

    expect($comment->refresh()->status)->toBe('skipped');
    Http::assertNothingSent();
});

it('reads the LinkedIn id of a published post from its id or URL', function () {
    $post = (new Post)->forceFill(['platform_post_id' => 'zp_1', 'platform_url' => 'https://www.linkedin.com/feed/update/urn:li:share:7526183703878864896']);

    expect(Boost::nativeRef($post))->toBe('urn:li:share:7526183703878864896');
});
