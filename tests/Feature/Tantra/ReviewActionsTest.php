<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Wednesday 11 Nov 2026, 07:00 in New York.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];

    $this->teammate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->teammate->id]);
    $this->team->members()->attach($this->teammate->id, membershipPivot('admin'));

    WorkspaceSettings::query()->create(['workspace_id' => $this->adi->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->team->id, 'brand_profile_key' => 'mate', 'review_owner_user_id' => $this->teammate->id]);

    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '08:30');
    }

    foreach ([$this->adi, $this->team] as $workspace) {
        SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::LinkedIn,
            'timezone' => 'America/New_York',
            'posting_schedule' => $weekdays,
        ]);
    }
});

/** @param array<string, mixed> $overrides */
function tantraItem(object $test, string $id, array $overrides = [], string $profile = 'adi'): ReviewItem
{
    ImportBatch::execute($test->owner, [
        'engine_batch_id' => '4',
        'posts' => [[
            'engine_post_id' => $id,
            'profile' => $profile,
            'platform' => 'linkedin',
            'date' => '2026-11-16',
            'time' => '08:30',
            'tz' => 'America/New_York',
            'text' => "Copy for $id.",
            ...$overrides,
        ]],
    ]);

    return ReviewItem::query()->where('engine_post_id', $id)->sole();
}

it('schedules an approved post at exactly its planned slot', function () {
    $item = tantraItem($this, 'A1');

    $result = ReviewActions::approve($this->owner, $item);
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    expect($result['slot_fallback'])->toBeFalse()
        ->and($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->utc()->toDateTimeString())->toBe('2026-11-16 13:30:00')
        ->and($item->refresh()->review_status)->toBe(ReviewStatus::Scheduled)
        ->and($item->approved_by)->toBe($this->owner->id)
        ->and($item->events()->pluck('kind')->last())->toBe(EventKind::Approve);
});

it('puts a post whose planned date is past in the next free slot and says so', function () {
    $item = tantraItem($this, 'A2', ['date' => '2026-11-09']);

    $result = ReviewActions::approve($this->owner, $item);

    expect($result['slot_fallback'])->toBeTrue()
        ->and($result['message'])->toContain('Moved to')
        ->and(Post::query()->where('post_group_id', $item->post_group_id)->sole()->scheduled_at->utc()->toDateTimeString())->toBe('2026-11-11 13:30:00')
        ->and($item->refresh()->slot_fallback)->toBeTrue();
});

it('never puts two approvals in the same slot', function () {
    $first = tantraItem($this, 'A1');
    $second = tantraItem($this, 'A2');

    ReviewActions::approve($this->owner, $first);
    $result = ReviewActions::approve($this->owner, $second);

    $times = Post::query()->whereIn('post_group_id', [$first->post_group_id, $second->post_group_id])->pluck('scheduled_at')->map->utc()->map->toDateTimeString()->all();

    expect($result['slot_fallback'])->toBeTrue()
        ->and(array_unique($times))->toHaveCount(2);
});

it('blocks approval until every number is signed off by its signer', function () {
    $item = tantraItem($this, 'M1', ['numbers_for_signoff' => [['text' => '400+ brands', 'signer' => 'adi'], 'six stages']], 'mate');

    expect(fn () => ReviewActions::approve($this->teammate, $item))->toThrow(ValidationException::class, '400+ brands');
    expect(fn () => ReviewActions::signOff($this->teammate, $item, 0))->toThrow(ValidationException::class, 'Only Adi');

    ReviewActions::signOff($this->owner, $item->refresh(), 0);
    ReviewActions::signOff($this->teammate, $item->refresh(), 1);

    expect(ReviewActions::approve($this->teammate, $item->refresh())['status'])->toBe('scheduled');
});

it('lets only the workspace review owner approve', function () {
    $teammatePost = tantraItem($this, 'M2', [], 'mate');
    $adiPost = tantraItem($this, 'A3');

    expect(fn () => ReviewActions::approve($this->owner, $teammatePost))->toThrow(ValidationException::class, 'Only the review owner')
        ->and(fn () => ReviewActions::approve($this->teammate, $adiPost))->toThrow(ValidationException::class, 'Only the review owner');
});

it('refuses a slot on or after the event date of a CTA post', function () {
    $item = tantraItem($this, 'A4', ['cta' => 'event', 'cta_date' => '2026-11-16']);

    expect(fn () => ReviewActions::approve($this->owner, $item))->toThrow(ValidationException::class, 'on or after the event');
    expect($item->refresh()->review_status)->toBe(ReviewStatus::InReview)
        ->and(Post::query()->where('post_group_id', $item->post_group_id)->sole()->status)->toBe(PostStatus::Draft);
});

it('approves an assisted manual item without scheduling anything', function () {
    $item = tantraItem($this, 'A5', ['platform' => 'x']);

    expect(ReviewActions::approve($this->owner, $item)['status'])->toBe('approved')
        ->and($item->refresh()->review_status)->toBe(ReviewStatus::Approved);
});

it('unapproves back to a draft, but not in the last ten minutes', function () {
    $item = tantraItem($this, 'A6');
    ReviewActions::approve($this->owner, $item);

    ReviewActions::unapprove($this->owner, $item->refresh());

    expect($item->refresh()->review_status)->toBe(ReviewStatus::InReview)
        ->and(Post::query()->where('post_group_id', $item->post_group_id)->sole()->status)->toBe(PostStatus::Draft);

    ReviewActions::approve($this->owner, $item->refresh());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 13:25:00', 'UTC'));

    expect(fn () => ReviewActions::unapprove($this->owner, $item->refresh()))->toThrow(ValidationException::class, 'too late');
});

it('requires a note to request changes and records it for the engine', function () {
    $item = tantraItem($this, 'A7');

    expect(fn () => ReviewActions::requestChanges($this->owner, $item, ''))->toThrow(ValidationException::class);

    ReviewActions::requestChanges($this->owner, $item, '@Engine shorter hook please');

    expect($item->refresh()->review_status)->toBe(ReviewStatus::ChangesRequested)
        ->and($item->events()->where('kind', EventKind::RequestChanges->value)->sole()->body)->toBe('@Engine shorter hook please');
});

it('approves through the studio endpoint and answers with the outcome', function () {
    $item = tantraItem($this, 'A8');

    $this->actingAs($this->owner)
        ->postJson(route('tantra.review.items.approve', $item))
        ->assertOk()
        ->assertJsonPath('item.review_status', 'scheduled')
        ->assertJsonPath('message', 'Scheduled for Mon 16 Nov 08:30.');

    $this->actingAs($this->teammate)
        ->postJson(route('tantra.review.items.comment', $item), ['body' => 'Looks good'])
        ->assertNotFound();
});
