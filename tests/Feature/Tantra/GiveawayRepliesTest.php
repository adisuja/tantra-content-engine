<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Giveaway\GiveawayReplies;
use App\Tantra\Giveaway\GiveawayReply;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Wednesday 11:00 in Kolkata: inside working hours.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:30:00', 'UTC'));
    config(['tantra.autoreply.enabled' => true, 'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);

    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->nelbin = User::factory()->create(['account_id' => $this->adi->account_id]);
    $ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->nelbin->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $ws->id, 'brand_profile_key' => 'nelbin', 'review_owner_user_id' => $this->nelbin->id, 'timezone' => 'Asia/Kolkata']);
    SocialAccount::factory()->create(['workspace_id' => $ws->id, 'platform' => Platform::LinkedIn]);
    MemberProfile::query()->create(['user_id' => $this->nelbin->id, 'unipile_account_id' => 'acc_nelbin', 'unipile_name' => 'Nelbin Joseph', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now()]);
    MemberProfile::query()->create(['user_id' => $this->adi->id, 'unipile_account_id' => 'acc_adi', 'unipile_name' => 'Adithya Murali', 'unipile_confirmed_at' => now()]);

    ImportBatch::execute($this->adi, ['engine_batch_id' => '4', 'posts' => [[
        'engine_post_id' => 'NA1', 'profile' => 'nelbin', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30',
        'tz' => 'America/New_York', 'text' => 'Giveaway post.',
        'resource' => ['name' => 'Stage Owner Map', 'keyword' => 'STAGES'],
    ]]]);

    $this->item = ReviewItem::query()->where('engine_post_id', 'NA1')->sole();
    $this->post = Post::query()->where('post_group_id', $this->item->post_group_id)->sole();
    $this->post->forceFill(['status' => PostStatus::Published, 'published_at' => now()->subHours(3), 'platform_post_id' => 'urn:li:share:7000000000000000002'])->saveQuietly();

    Http::fake([
        'unipile.test/v2/acc_nelbin/users/me' => Http::response(['provider_id' => 'nelbin_li']),
        'unipile.test/v2/acc_nelbin/posts/*/comments?*' => Http::response(['data' => [
            ['id' => 'c1', 'text' => 'STAGES please', 'author' => ['id' => 'jane_li', 'name' => 'Jane Doe', 'profile_url' => 'https://www.linkedin.com/in/janedoe']],
            ['id' => 'c2', 'text' => 'My own note', 'author' => ['id' => 'nelbin_li', 'name' => 'Nelbin Joseph']],
            ['id' => 'c3', 'text' => 'Team comment', 'author' => ['id' => 'adi_li', 'name' => 'Adithya Murali']],
        ]]),
        'unipile.test/v2/acc_nelbin/posts/soc_2/comments/c1' => Http::response(['id' => 'r1'], 201),
        'unipile.test/v2/acc_nelbin/posts/*' => Http::response(['id' => 'soc_2']),
    ]);
});

it('answers each organic comment once, from the author, and lists them for the DM', function () {
    expect(GiveawayReplies::run(app(UnipileClient::class)))->toBe(1);

    $reply = GiveawayReply::query()->sole();
    expect($reply->comment_id)->toBe('c1')
        ->and($reply->status)->toBe('replied')
        ->and($reply->reply_text)->toContain('Jane')
        ->and($reply->reply_text)->toContain('Stage Owner Map');

    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/acc_nelbin/posts/soc_2/comments/c1'));

    // A second pass does not answer anyone twice.
    expect(GiveawayReplies::run(app(UnipileClient::class)))->toBe(0);

    $this->actingAs($this->adi)->get(route('tantra.review.show', $this->item->batch_id))
        ->assertInertia(fn ($page) => $page->where('items.0.dm_list.0.name', 'Jane Doe')->where('items.0.dm_list.0.sent', false));

    $this->actingAs($this->adi)->postJson(route('tantra.review.items.dm-sent', [$this->item, $reply]), ['sent' => true])->assertOk();
    expect($reply->refresh()->dm_sent_at)->not->toBeNull();
});

it('waits outside working hours', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 16:30:00', 'UTC')); // 22:00 in Kolkata

    expect(GiveawayReplies::run(app(UnipileClient::class)))->toBe(0);
    Http::assertNothingSent();
});
