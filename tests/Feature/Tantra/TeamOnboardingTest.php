<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Review\Actions\DraftMaker;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use App\Tantra\Team\TeammateWelcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];

    $this->nelbin = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Nelbin']);
    $this->nelbin->members()->attach($this->owner->id, membershipPivot('admin'));
    WorkspaceSettings::query()->create(['workspace_id' => $this->nelbin->id, 'brand_profile_key' => 'nelbin', 'review_owner_user_id' => $this->owner->id]);
});

function tantraPushNelbinPost(object $test): ReviewItem
{
    $token = $test->withHeaders($test->headers)
        ->post(route('tantra.api.uploads.store', ['workspace_id' => $test->nelbin->id]), ['media' => UploadedFile::fake()->image('n1.png', 1080, 1350)])
        ->assertCreated()
        ->json('data.upload_token');

    $test->withHeaders($test->headers)->postJson(route('tantra.api.batches.store'), [
        'engine_batch_id' => '4',
        'posts' => [[
            'engine_post_id' => 'NA1',
            'profile' => 'nelbin',
            'platform' => 'linkedin',
            'date' => '2026-11-16',
            'time' => '08:30',
            'tz' => 'America/New_York',
            'text' => 'Nelbin post.',
            'first_comment' => 'Comment STAGES and I will send it.',
            'upload_tokens' => [$token],
        ]],
    ])->assertOk();

    return ReviewItem::query()->where('engine_post_id', 'NA1')->sole();
}

function tantraConnectLinkedIn(Workspace $workspace): SocialAccount
{
    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '08:30');
    }

    return SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'America/New_York',
        'posting_schedule' => $weekdays,
    ]);
}

it('holds the media of a post with no channel so the upload pruning cannot remove it', function () {
    $item = tantraPushNelbinPost($this);

    expect($item->post_group_id)->toBeNull()
        ->and($item->flags)->toContain(DraftMaker::NO_CHANNEL_FLAG)
        ->and(Media::query()->where('workspace_id', $this->nelbin->id)->sole()->collection)->toBe(DraftMaker::HELD_COLLECTION);
});

it('makes the drafts, with the held media, as soon as LinkedIn is connected', function () {
    $item = tantraPushNelbinPost($this);

    tantraConnectLinkedIn($this->nelbin);

    $item->refresh();
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->content)->toBe('Nelbin post.')
        ->and($post->media)->toHaveCount(1)
        ->and($item->flags)->not->toContain(DraftMaker::NO_CHANNEL_FLAG);
});

it('lets Adi approve until the teammate joins, then only the teammate', function () {
    Mail::fake();
    $item = tantraPushNelbinPost($this);
    tantraConnectLinkedIn($this->nelbin);

    $this->artisan('tantra:invite', ['workspace' => 'Nelbin', 'email' => 'nelbin@example.com', 'name' => 'Nelbin Joseph', '--send' => true])
        ->assertSuccessful();

    $teammate = User::query()->where('email', 'nelbin@example.com')->sole();
    Mail::assertSent(TeammateWelcome::class, fn (TeammateWelcome $m): bool => $m->hasTo('nelbin@example.com'));

    expect($teammate->account_id)->toBe($this->owner->account_id)
        ->and($this->nelbin->hasMember($teammate))->toBeTrue()
        ->and(ReviewGate::reviewOwnerId($item->refresh()))->toBe($this->owner->id);

    $teammate->update(['password' => 'a-strong-password']);

    expect(ReviewGate::reviewOwnerId($item->refresh()))->toBe($teammate->id)
        ->and(ReviewGate::waitingCount($teammate->refresh()))->toBe(1)
        ->and(fn () => ReviewActions::approve($this->owner, $item))->toThrow(Illuminate\Validation\ValidationException::class);

    expect(ReviewActions::approve($teammate, $item->refresh())['status'])->toBe('scheduled');
});

it('sends a joining teammate to the set-password page', function () {
    $this->artisan('tantra:invite', ['workspace' => 'Nelbin', 'email' => 'nelbin@example.com', 'name' => 'Nelbin Joseph'])->assertSuccessful();
    $teammate = User::query()->where('email', 'nelbin@example.com')->sole();

    $this->get(URL::temporarySignedRoute('tantra.join', now()->addDays(14), ['user' => $teammate->id]))
        ->assertRedirectContains('/reset-password/');

    $this->get(route('tantra.join', ['user' => $teammate->id]))->assertForbidden();
});

it('makes the drafts at approval when the channel was connected before the import', function () {
    $item = tantraPushNelbinPost($this);
    // A channel that exists without having triggered the listener (e.g. connected in another request).
    SocialAccount::withoutEvents(fn () => tantraConnectLinkedIn($this->nelbin));

    expect($item->refresh()->post_group_id)->toBeNull()
        ->and(ReviewActions::approve($this->owner, $item)['status'])->toBe('scheduled')
        ->and(Post::query()->where('post_group_id', $item->refresh()->post_group_id)->sole()->status)->toBe(PostStatus::Scheduled);
});
