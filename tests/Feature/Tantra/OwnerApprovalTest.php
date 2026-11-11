<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostApproval;
use App\Support\PostingSchedule;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $result = createApiTestToken();
    $this->adi = $result['user'];

    // Nelbin's workspace, created by Adi; Nelbin is its admin and review owner.
    $this->nelbin = User::factory()->create(['account_id' => $this->adi->account_id]);
    $this->ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    $this->ws->members()->attach($this->nelbin->id, membershipPivot('admin'));
    $this->ws->members()->attach($this->adi->id, membershipPivot('admin'));
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'nelbin', 'review_owner_user_id' => $this->nelbin->id]);

    $this->channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->ws->id, 'timezone' => 'UTC',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(4, '09:00'),
    ]);
});

function tantraScheduleAs(object $test, User $actor)
{
    return CreatePosts::execute($test->ws, $actor, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Written for Nelbin.',
        'media' => [],
        'destinations' => [['social_account_id' => $test->channel->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []]],
    ])->sole();
}

it('sends the Account owner\'s post in a teammate\'s workspace to the teammate for approval', function () {
    expect($this->adi->requiresApprovalIn($this->ws))->toBeTrue()
        ->and($this->adi->canPublishDirectlyIn($this->ws))->toBeFalse()
        ->and(Gate::forUser($this->adi)->allows('approvePosts', $this->ws))->toBeFalse()
        ->and(PostApproval::isRequired($this->ws, $this->adi, PostStatus::Scheduled->value))->toBeTrue()
        ->and($this->ws->approvers()->pluck('id')->all())->toBe([$this->nelbin->id]);

    expect(tantraScheduleAs($this, $this->adi)->status)->toBe(PostStatus::PendingApproval)
        ->and(tantraScheduleAs($this, $this->nelbin)->status)->toBe(PostStatus::Scheduled);
});

it('lets the Account owner act for a teammate who has not joined yet, and stops the moment they join', function () {
    $this->nelbin->forceFill(['password' => null])->save();

    expect($this->adi->canPublishDirectlyIn($this->ws))->toBeTrue()
        ->and($this->ws->approvers()->pluck('id'))->toContain($this->adi->id);

    $this->nelbin->forceFill(['password' => bcrypt('set by Nelbin')])->save();

    expect($this->adi->canPublishDirectlyIn($this->ws))->toBeFalse()
        ->and($this->adi->requiresApprovalIn($this->ws))->toBeTrue();
});

it('changes nothing in a workspace with no Tantra review owner', function () {
    $plain = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);

    expect($this->adi->canPublishDirectlyIn($plain))->toBeTrue()
        ->and($this->adi->requiresApprovalIn($plain))->toBeFalse();
});
