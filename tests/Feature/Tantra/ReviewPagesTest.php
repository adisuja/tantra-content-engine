<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Events\ReviewItemChanged;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];

    $this->teammate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->teammate->id]);
    $this->team->members()->attach($this->teammate->id, membershipPivot('admin'));
    $this->teammate->update(['current_workspace_id' => $this->team->id]);

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
            'display_name' => $workspace->is($this->adi) ? 'Adithya Murali' : 'Teammate',
        ]);
    }

    ImportBatch::execute($this->owner, [
        'engine_batch_id' => '4',
        'title' => 'Batch 4',
        'posts' => [
            [
                'engine_post_id' => 'A1',
                'profile' => 'adi',
                'platform' => 'linkedin',
                'date' => '2026-11-16',
                'time' => '08:30',
                'tz' => 'America/New_York',
                'text' => 'Adi post copy.',
                'first_comment' => 'Link in here.',
                'pillar' => 'Proof',
                'numbers_for_signoff' => [['text' => '400+ brands', 'signer' => 'adi']],
                'profile_card' => ['name' => 'Adithya Murali', 'headline' => 'CEO at Tantra', 'kind' => 'person'],
                'engagement_comments' => [['by' => 'aquila', 'name' => 'Aquila Eldho', 'text' => 'Great one.']],
            ],
            [
                'engine_post_id' => 'M1',
                'profile' => 'mate',
                'platform' => 'linkedin',
                'date' => '2026-11-17',
                'time' => '08:30',
                'tz' => 'America/New_York',
                'text' => 'Teammate post copy.',
            ],
        ],
    ]);
});

it('lists batches with their counts in the inbox', function () {
    $this->actingAs($this->owner)
        ->get(route('tantra.review.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/review/Index')
            ->has('batches', 2)
            ->where('batches.0.counts.waiting', 1)
            ->where('tantraReview.waiting', 1));
});

it('renders each post with its phone card, sign-off and permissions', function () {
    $batch = Batch::query()->where('workspace_id', $this->adi->id)->sole();

    $this->actingAs($this->owner)
        ->get(route('tantra.review.show', $batch))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/review/Batch')
            ->has('items', 1)
            ->where('items.0.engine_post_id', 'A1')
            ->where('items.0.profile.headline', 'CEO at Tantra')
            ->where('items.0.first_comment', 'Link in here.')
            ->where('items.0.can_review', true)
            ->where('items.0.numbers_for_signoff.0.can_sign', true)
            ->where('items.0.team_comments.0.name', 'Aquila Eldho')
            ->where('items.0.review_status', 'in_review'));
});

it('hides a batch from a teammate outside its workspace', function () {
    $batch = Batch::query()->where('workspace_id', $this->adi->id)->sole();

    $this->actingAs($this->teammate)->get(route('tantra.review.show', $batch))->assertNotFound();

    $this->actingAs($this->teammate)
        ->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page->has('batches', 1)->where('tantraReview.waiting', 1));
});

it('lets the Account owner open a teammate batch but not approve it', function () {
    $batch = Batch::query()->where('workspace_id', $this->team->id)->sole();

    $this->actingAs($this->owner)
        ->get(route('tantra.review.show', $batch))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('items.0.can_review', false));
});

it('tells open review screens when an item changes', function () {
    Event::fake([ReviewItemChanged::class]);
    $item = ReviewItem::query()->where('engine_post_id', 'M1')->sole();

    ReviewActions::comment($this->teammate, $item, 'Looks right');

    Event::assertDispatched(ReviewItemChanged::class, fn (ReviewItemChanged $e): bool => $e->itemId === $item->id
        && $e->batchId === $item->batch_id
        && $e->broadcastOn()[0]->name === "private-tantra.review.batch.{$item->batch_id}");
});
