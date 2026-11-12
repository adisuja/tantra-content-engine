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

it('shows the posts this person approves as phone cards, waiting first', function () {
    $this->actingAs($this->owner)
        ->get(route('tantra.review.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/review/Index')
            ->where('tab', 'review')
            ->has('items', 1)
            ->where('items.0.engine_post_id', 'A1')
            ->where('items.0.profile.headline', 'CEO at Tantra')
            ->where('items.0.first_comment', 'Link in here.')
            ->where('items.0.can_review', true)
            ->where('items.0.numbers_for_signoff.0.can_sign', true)
            ->where('items.0.team_comments.0.name', 'Aquila Eldho')
            ->where('items.0.review_status', 'in_review')
            ->where('counts.review', 1)
            ->where('tantraReview.waiting', 1));
});

it('keeps a batch from a teammate outside its workspace, and shows them only their own posts', function () {
    $batch = Batch::query()->where('workspace_id', $this->adi->id)->sole();

    $this->actingAs($this->teammate)->get(route('tantra.review.show', $batch))->assertNotFound();

    $this->actingAs($this->teammate)
        ->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.engine_post_id', 'M1')->where('tantraReview.waiting', 1));
});

it('opens a batch link from an email on the review screen; the Account owner sees a teammate post but cannot approve it', function () {
    $batch = Batch::query()->where('workspace_id', $this->team->id)->sole();

    $this->actingAs($this->owner)->get(route('tantra.review.show', $batch))
        ->assertRedirect(route('tantra.review.index', ['batch' => $batch->id]));

    $this->actingAs($this->owner)
        ->get(route('tantra.review.index', ['batch' => $batch->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('batch.id', $batch->id)->where('items.0.engine_post_id', 'M1')->where('items.0.can_review', false));
});

it('lists published posts with their link on the Published tab', function () {
    $item = ReviewItem::query()->where('engine_post_id', 'A1')->sole();
    $item->forceFill(['review_status' => App\Tantra\Review\Enums\ReviewStatus::Published])->save();
    App\Models\Post::query()->where('post_group_id', $item->post_group_id)->update(['platform_url' => 'https://www.linkedin.com/feed/update/urn:li:share:1/', 'published_at' => now()]);

    $this->actingAs($this->owner)->get(route('tantra.review.index', ['tab' => 'published']))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'published')
            ->has('items', 1)
            ->where('items.0.post_url', 'https://www.linkedin.com/feed/update/urn:li:share:1/')
            ->where('counts.published', 1)
            ->where('counts.review', 0));
});

it('moves an approved post from Review to Scheduled', function () {
    $item = ReviewItem::query()->where('engine_post_id', 'A1')->sole();
    ReviewActions::signOff($this->owner, $item, 0);
    ReviewActions::approve($this->owner, $item->refresh());

    $this->actingAs($this->owner)->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('counts.review', 0)->where('counts.scheduled', 1));

    $this->actingAs($this->owner)->get(route('tantra.review.index', ['tab' => 'scheduled']))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'scheduled')->where('items.0.engine_post_id', 'A1')->where('items.0.review_status', 'scheduled'));
});

it('says how each team comment goes out, and why one cannot yet', function () {
    config(['tantra.boost.enabled' => true, 'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);

    $this->actingAs($this->owner)->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.delivery.post.via', 'trypost')
            ->where('items.0.delivery.entries.0.by', 'aquila')
            ->where('items.0.delivery.entries.0.via', 'unipile')
            ->where('items.0.delivery.entries.0.state', 'blocked')
            ->where('items.0.delivery.entries.0.reason', 'no Engine login for aquila yet'));
});

it('lands people in a Tantra workspace on Review the first time they open the calendar', function () {
    $this->owner->update(['current_workspace_id' => $this->adi->id]);

    $this->actingAs($this->owner)->get(route('app.calendar'))->assertRedirect(route('tantra.review.index'));
    $this->actingAs($this->owner)->get(route('app.calendar'))->assertOk();
});

it('tells open review screens when an item changes', function () {
    Event::fake([ReviewItemChanged::class]);
    $item = ReviewItem::query()->where('engine_post_id', 'M1')->sole();

    ReviewActions::comment($this->teammate, $item, 'Looks right');

    Event::assertDispatched(ReviewItemChanged::class, fn (ReviewItemChanged $e): bool => $e->itemId === $item->id
        && $e->batchId === $item->batch_id
        && $e->broadcastOn()[0]->name === "private-tantra.review.batch.{$item->batch_id}");
});
