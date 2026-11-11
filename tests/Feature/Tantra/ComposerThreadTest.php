<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Composer\ComposerThreadWriter;
use App\Tantra\Composer\MakeComposerThread;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;

beforeEach(function () {
    config(['tantra.boost.enabled' => true, 'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);

    $result = createApiTestToken();
    $this->adi = $result['user'];
    WorkspaceSettings::query()->create(['workspace_id' => $result['workspace']->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->adi->id]);
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $result['workspace']->id, 'platform' => Platform::LinkedIn]);

    $nelbin = User::factory()->create(['account_id' => $this->adi->account_id]);
    $ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $ws->id, 'brand_profile_key' => 'nelbin', 'review_owner_user_id' => $nelbin->id]);
    MemberProfile::query()->create(['user_id' => $nelbin->id, 'unipile_account_id' => 'acc_n', 'unipile_confirmed_at' => now(), 'boost_consent_at' => now(), 'expertise' => 'Outbound for SaaS.']);

    $this->post = Post::factory()->forAccount($this->channel)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'content' => 'Why reply speed beats volume.', 'post_group_id' => (string) str()->uuid()]);
});

it('writes a team thread for a scheduled composer post, with the author answering each comment', function () {
    ComposerThreadWriter::fake([[
        'comments' => [
            ['by' => 'nelbin', 'text' => 'We saw the same with our SDR replies.', 'reply' => 'Glad it matches what you see, Nelbin.'],
            ['by' => 'stranger', 'text' => 'Not on the team.', 'reply' => 'Dropped.'],
            ['by' => 'nelbin', 'text' => 'A second one from me.', 'reply' => 'Dropped too.'],
        ],
    ]]);

    (new MakeComposerThread($this->post->id))->handle();

    $item = ReviewItem::query()->where('post_group_id', $this->post->post_group_id)->sole();
    expect($item->source)->toBe('composer')
        ->and($item->engine_batch_id)->toBe('composer')
        ->and($item->payload['engagement_comments'])->toBe([
            ['by' => 'nelbin', 'text' => 'We saw the same with our SDR replies.', 'reply_to' => null],
            ['by' => 'adi', 'text' => 'Glad it matches what you see, Nelbin.', 'reply_to' => 0],
        ]);

    // Once only.
    (new MakeComposerThread($this->post->id))->handle();
    expect(ReviewItem::query()->where('post_group_id', $this->post->post_group_id)->count())->toBe(1);
});

it('writes nothing while boosting is off', function () {
    config(['tantra.boost.enabled' => false]);
    ComposerThreadWriter::fake()->preventStrayPrompts();

    (new MakeComposerThread($this->post->id))->handle();

    ComposerThreadWriter::assertNeverPrompted();
    expect(ReviewItem::query()->count())->toBe(0);
});

it('drops a comment or reply with a dash', function () {
    expect(MakeComposerThread::thread([['by' => 'nelbin', 'text' => "Yes \u{2014} exactly.", 'reply' => 'Thanks.']], ['nelbin'], 'adi'))->toBe([]);
});
