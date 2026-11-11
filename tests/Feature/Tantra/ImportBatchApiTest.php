<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];

    $this->teammate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->shweta = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->teammate->id, 'name' => 'Shweta']);
    $this->shweta->members()->attach($this->teammate->id, membershipPivot('admin'));
    $this->shweta->members()->attach($this->owner->id, membershipPivot('admin'));

    WorkspaceSettings::query()->create(['workspace_id' => $this->adi->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->shweta->id, 'brand_profile_key' => 'shweta', 'review_owner_user_id' => $this->teammate->id]);

    $this->adiLinkedIn = SocialAccount::factory()->create(['workspace_id' => $this->adi->id, 'platform' => Platform::LinkedIn]);
    $this->shwetaLinkedIn = SocialAccount::factory()->create(['workspace_id' => $this->shweta->id, 'platform' => Platform::LinkedIn]);
});

function tantraUpload(object $test, Workspace $workspace, UploadedFile $file): string
{
    return $test->withHeaders($test->headers)
        ->post(route('tantra.api.uploads.store', ['workspace_id' => $workspace->id]), ['media' => $file])
        ->assertCreated()
        ->json('data.upload_token');
}

/** @param array<string, mixed> $overrides */
function tantraPost(string $id, string $profile = 'adi', array $overrides = []): array
{
    return [
        'engine_post_id' => $id,
        'profile' => $profile,
        'platform' => 'linkedin',
        'date' => '2026-11-16',
        'time' => '08:30',
        'tz' => 'America/New_York',
        'text' => "Post $id body.\n\nSecond line.",
        'first_comment' => "First comment for $id.",
        'format' => 'Image',
        'pillar' => 'AI orchestration',
        'sample' => ['id' => 'ID03', 'creator' => 'Ipsita Dhar'],
        'numbers_for_signoff' => ['250+ real funnel elements', ['text' => 'six stages', 'signer' => 'adi']],
        ...$overrides,
    ];
}

function tantraPush(object $test, array $posts, array $extra = []): Illuminate\Testing\TestResponse
{
    return $test->withHeaders($test->headers)->postJson(route('tantra.api.batches.store'), [
        'engine_batch_id' => '3',
        'title' => 'Batch 3',
        'posts' => $posts,
        ...$extra,
    ]);
}

it('imports engine posts as review items with a draft per channel', function () {
    $token = tantraUpload($this, $this->adi, UploadedFile::fake()->image('a1.png', 1080, 1350));

    tantraPush($this, [tantraPost('A1', 'adi', ['upload_tokens' => [$token]])])
        ->assertOk()
        ->assertJsonPath('data.items.0.outcome', 'created');

    $item = ReviewItem::query()->sole();
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    expect($item->review_status)->toBe(ReviewStatus::InReview)
        ->and($item->workspace_id)->toBe($this->adi->id)
        ->and($item->numbers_for_signoff)->toHaveCount(2)
        ->and($item->numbers_for_signoff[0]['signer'])->toBe('owner')
        ->and($item->numbers_for_signoff[1]['signer'])->toBe('adi')
        ->and($post->status)->toBe(PostStatus::Draft)
        ->and($post->social_account_id)->toBe($this->adiLinkedIn->id)
        ->and($post->content)->toBe("Post A1 body.\n\nSecond line.")
        ->and(data_get($post->meta, 'thread_replies.0.text'))->toBe('First comment for A1.')
        ->and($post->media)->toHaveCount(1)
        ->and($post->labels()->pluck('name')->all())->toBe(['From batch 3'])
        ->and($item->events()->first()->kind)->toBe(EventKind::Imported);
});

it('pushing the same batch twice updates items instead of duplicating them', function () {
    tantraPush($this, [tantraPost('A1'), tantraPost('A2')])->assertOk();
    tantraPush($this, [tantraPost('A1', 'adi', ['text' => 'Reworked copy.']), tantraPost('A2')])
        ->assertOk()
        ->assertJsonPath('data.items.0.outcome', 'updated');

    $a1 = ReviewItem::query()->where('engine_post_id', 'A1')->sole();

    expect(ReviewItem::query()->count())->toBe(2)
        ->and($a1->revision)->toBe(2)
        ->and(Post::query()->where('social_account_id', $this->adiLinkedIn->id)->count())->toBe(2)
        ->and(Post::query()->where('post_group_id', $a1->post_group_id)->sole()->content)->toBe('Reworked copy.')
        ->and(WorkspaceLabel::query()->where('workspace_id', $this->adi->id)->count())->toBe(1);
});

it('leaves approved items alone on a re-push unless force-reopened', function () {
    tantraPush($this, [tantraPost('A1')])->assertOk();
    ReviewItem::query()->update(['review_status' => ReviewStatus::Approved->value]);

    tantraPush($this, [tantraPost('A1', 'adi', ['text' => 'Changed after approval.'])])
        ->assertOk()
        ->assertJsonPath('data.items.0.outcome', 'unchanged');

    expect(ReviewItem::query()->sole()->revision)->toBe(1);

    tantraPush($this, [tantraPost('A1', 'adi', ['text' => 'Reopened copy.'])], ['force_reopen' => ['A1']])
        ->assertOk()
        ->assertJsonPath('data.items.0.outcome', 'updated');

    expect(ReviewItem::query()->sole()->review_status)->toBe(ReviewStatus::InReview);
});

it('rejects a post whose profile has no workspace, naming the profile, and creates nothing', function () {
    tantraPush($this, [tantraPost('A1'), tantraPost('Z1', 'nobody')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('posts')
        ->assertSee('nobody');

    expect(ReviewItem::query()->count())->toBe(0)
        ->and(Post::query()->count())->toBe(0);
});

it('attaches media uploaded with the owner key to a post in a teammate workspace', function () {
    $token = tantraUpload($this, $this->shweta, UploadedFile::fake()->image('s1.png', 1080, 1350));

    tantraPush($this, [tantraPost('S1', 'shweta', ['upload_tokens' => [$token]])])->assertOk();

    $item = ReviewItem::query()->sole();
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    expect($item->workspace_id)->toBe($this->shweta->id)
        ->and($post->workspace_id)->toBe($this->shweta->id)
        ->and($post->social_account_id)->toBe($this->shwetaLinkedIn->id)
        ->and($post->media)->toHaveCount(1);
});

it('imports a personal profile poll and an X version as assisted manual items without drafts', function () {
    tantraPush($this, [
        tantraPost('A3', 'adi', ['media' => ['type' => 'poll', 'question' => 'Which stage leaks most?', 'options' => ['Traffic', 'Nurture']]]),
        tantraPost('A1'),
    ])->assertOk();
    tantraPush($this, [tantraPost('A1x', 'adi', ['platform' => 'x', 'parent_engine_post_id' => 'A1'])])->assertOk();

    $poll = ReviewItem::query()->where('engine_post_id', 'A3')->sole();
    $x = ReviewItem::query()->where('engine_post_id', 'A1x')->sole();
    $parent = ReviewItem::query()->where('engine_post_id', 'A1')->sole();

    expect($poll->assisted_manual)->toBeTrue()
        ->and($poll->post_group_id)->toBeNull()
        ->and($poll->media_manifest['poll']['question'])->toBe('Which stage leaks most?')
        ->and($x->assisted_manual)->toBeTrue()
        ->and($x->post_group_id)->toBeNull()
        ->and($x->parent_review_item_id)->toBe($parent->id);
});

it('keeps an item without a connected channel and says why', function () {
    $this->adiLinkedIn->delete();

    tantraPush($this, [tantraPost('A1')])->assertOk();

    $item = ReviewItem::query()->sole();

    expect($item->post_group_id)->toBeNull()
        ->and(collect($item->flags)->join(' '))->toContain('No linkedin channel is connected');
});

it('lists profiles with their workspaces and channels', function () {
    $this->withHeaders($this->headers)->getJson(route('tantra.api.profiles.index'))
        ->assertOk()
        ->assertJsonFragment(['profile' => 'adi', 'workspace_id' => $this->adi->id])
        ->assertJsonFragment(['profile' => 'shweta', 'workspace_id' => $this->shweta->id]);
});

it('refuses a key that does not belong to the Account owner', function () {
    $teammateToken = passportToken($this->teammate, $this->shweta);

    $this->withHeaders(['Authorization' => 'Bearer '.$teammateToken])
        ->postJson(route('tantra.api.batches.store'), ['engine_batch_id' => '3', 'posts' => [tantraPost('A1')]])
        ->assertForbidden();

    expect(ReviewItem::query()->count())->toBe(0);
});

it('refuses an upload into a workspace of another Account', function () {
    $foreign = Workspace::factory()->create();

    $this->withHeaders($this->headers)
        ->post(route('tantra.api.uploads.store', ['workspace_id' => $foreign->id]), ['media' => UploadedFile::fake()->image('x.png')])
        ->assertNotFound();
});

it('returns @Engine feedback for a batch', function () {
    tantraPush($this, [tantraPost('A1')])->assertOk();
    $item = ReviewItem::query()->sole();
    $item->record(EventKind::Comment, $this->owner, '@Engine make the hook shorter');
    $item->record(EventKind::Comment, $this->owner, 'Note to self, not for the engine');

    $this->withHeaders($this->headers)->getJson(route('tantra.api.batches.feedback', $item->batch_id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', '@Engine make the hook shorter');
});

it('maps a profile to a workspace with the command', function () {
    $this->artisan('tantra:map-profile', ['workspace' => 'Shweta', 'profile' => 'shweta-2', '--owner' => $this->teammate->email])
        ->assertSuccessful();

    expect(WorkspaceSettings::query()->find($this->shweta->id)->brand_profile_key)->toBe('shweta-2');
});
