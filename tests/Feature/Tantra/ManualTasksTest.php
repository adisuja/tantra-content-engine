<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Manual\ManualTaskDue;
use App\Tantra\Manual\ManualTasks;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];
    WorkspaceSettings::query()->create(['workspace_id' => $this->adi->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);

    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '08:30');
    }
    SocialAccount::factory()->create(['workspace_id' => $this->adi->id, 'platform' => Platform::LinkedIn, 'timezone' => 'America/New_York', 'posting_schedule' => $weekdays]);

    ImportBatch::execute($this->owner, ['engine_batch_id' => '4', 'posts' => [
        ['engine_post_id' => 'A1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-16', 'time' => '08:30', 'tz' => 'America/New_York', 'text' => 'LinkedIn copy.'],
        ['engine_post_id' => 'A1x', 'parent_engine_post_id' => 'A1', 'profile' => 'adi', 'platform' => 'x', 'date' => '2026-11-16', 'time' => '10:30', 'tz' => 'America/New_York', 'text' => 'X copy.', 'thread' => ['Second post.']],
        ['engine_post_id' => 'P1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-17', 'time' => '08:30', 'tz' => 'America/New_York', 'text' => 'Vote below.', 'media' => ['type' => 'poll', 'question' => 'Which first?', 'options' => ['Leads', 'Revenue']]],
    ]]);

    $this->linkedin = ReviewItem::query()->where('engine_post_id', 'A1')->sole();
    $this->x = ReviewItem::query()->where('engine_post_id', 'A1x')->sole();
    $this->poll = ReviewItem::query()->where('engine_post_id', 'P1')->sole();
});

it('makes an X task due two hours after its LinkedIn post goes out', function () {
    ReviewActions::approve($this->owner, $this->x);
    $task = ManualTask::query()->where('review_item_id', $this->x->id)->sole();

    expect($task->kind)->toBe('x_thread')
        ->and($task->status)->toBe('waiting')
        ->and($task->due_at)->toBeNull()
        ->and($task->thread)->toBe(['Second post.']);

    ReviewActions::approve($this->owner, $this->linkedin);
    ManualTasks::tick();

    expect($task->refresh()->due_at->utc()->toDateTimeString())->toBe('2026-11-16 15:30:00')
        ->and($task->status)->toBe('waiting');
});

it('times a personal poll at its own slot and tells the owner when it is due', function () {
    Mail::fake();
    ReviewActions::approve($this->owner, $this->poll);
    $task = ManualTask::query()->where('review_item_id', $this->poll->id)->sole();

    expect($task->kind)->toBe('linkedin_poll')
        ->and($task->poll)->toEqual(['question' => 'Which first?', 'options' => ['Leads', 'Revenue']])
        ->and($task->due_at->utc()->toDateTimeString())->toBe('2026-11-17 13:30:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-17 13:31:00', 'UTC'));
    ManualTasks::tick();

    expect($task->refresh()->status)->toBe('due');
    Mail::assertQueued(ManualTaskDue::class, fn (ManualTaskDue $m): bool => $m->hasTo($this->owner->email) && $m->envelope()->subject === 'Post your LinkedIn poll now');

    $this->travelTo(CarbonImmutable::parse('2026-11-19 14:00:00', 'UTC'));
    ManualTasks::tick();

    expect($task->refresh()->status)->toBe('expired');
});

it('closes the task and publishes the item when the owner marks it posted', function () {
    ReviewActions::approve($this->owner, $this->x);
    $task = ManualTask::query()->where('review_item_id', $this->x->id)->sole();

    expect(fn () => ManualTasks::markPosted($this->owner, $task, 'https://example.com/x'))->toThrow(ValidationException::class);

    $this->actingAs($this->owner)
        ->postJson(route('tantra.tasks.posted', $task), ['url' => 'https://x.com/adisuja/status/1'])
        ->assertOk();

    expect($task->refresh()->status)->toBe('posted')
        ->and($task->posted_url)->toBe('https://x.com/adisuja/status/1')
        ->and($this->x->refresh()->review_status)->toBe(ReviewStatus::Published);
});

it('removes the task when the item is unapproved', function () {
    ReviewActions::approve($this->owner, $this->x);
    ReviewActions::unapprove($this->owner, $this->x->refresh());

    expect(ManualTask::query()->count())->toBe(0);
});

it('lists the tasks on the Post by hand page', function () {
    ReviewActions::approve($this->owner, $this->poll);

    $this->actingAs($this->owner)
        ->get(route('tantra.tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/tasks/Index')
            ->has('tasks', 1)
            ->where('tasks.0.kind', 'linkedin_poll')
            ->where('tasks.0.can_mark', true)
            ->where('tantraReview.tasks_open', 1));
});

it('gives a poll the next free slot when its planned one is taken, and holds that slot', function () {
    ReviewActions::approve($this->owner, $this->linkedin); // Mon 16 Nov 08:30 New York
    $this->poll->forceFill(['planned_date' => '2026-11-16'])->save();

    ReviewActions::approve($this->owner, $this->poll);
    $task = ManualTask::query()->where('review_item_id', $this->poll->id)->sole();
    $channel = SocialAccount::query()->where('workspace_id', $this->adi->id)->sole();

    // Wednesday 11 Nov 08:30 in New York is the first free slot.
    expect($task->social_account_id)->toBe($channel->id)
        ->and($task->slot_fallback)->toBeTrue()
        ->and($task->due_at->utc()->toDateTimeString())->toBe('2026-11-11 13:30:00');

    // Posts queued after it skip the poll's slot, and the composer sees it taken.
    expect(App\Actions\Post\Queue\ListFreeQueueSlots::handle($channel)[0]->utc()->toDateTimeString())->toBe('2026-11-12 13:30:00')
        ->and(App\Actions\Post\Queue\ListTakenSlots::handle([$channel->id], now(), now()->addDays(7))[$channel->id])
        ->toBe(['2026-11-11T13:30:00Z', '2026-11-16T13:30:00Z']);

    $this->actingAs($this->owner)->get(route('app.calendar', ['view' => 'week', 'week' => '2026-11-09']))
        ->assertInertia(fn (Assert $page) => $page->has('tantraManualTasks', 1)
            ->where('tantraManualTasks.0.kind', 'linkedin_poll')
            ->where('tantraManualTasks.0.fallback', true));
});
