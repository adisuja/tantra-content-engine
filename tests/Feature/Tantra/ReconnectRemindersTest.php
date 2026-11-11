<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Channels\ReconnectChannel;
use App\Tantra\Channels\ReconnectReminders;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 04:00:00', 'UTC'));
    Mail::fake();

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ownerWs = $result['workspace'];
    $this->mate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->mateWs = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->mateWs->members()->attach($this->mate->id, membershipPivot('admin'));

    WorkspaceSettings::query()->create(['workspace_id' => $this->ownerWs->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->mateWs->id, 'brand_profile_key' => 'mate', 'review_owner_user_id' => $this->mate->id]);

    // LinkedIn gives no refresh token for a personal connection: it simply runs out.
    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->mateWs->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Mate Doe',
        'refresh_token' => null, 'token_expires_at' => now()->addDays(5)->addHour(),
    ]);
});

it('tells the teammate who reviews there, once at a week out and once the day before', function () {
    expect(ReconnectReminders::run())->toBe(1)->and(ReconnectReminders::run())->toBe(0);
    Mail::assertQueued(ReconnectChannel::class, fn (ReconnectChannel $m): bool => $m->hasTo($this->mate->email)
        && $m->state === 'expiring' && $m->daysLeft === 5);

    $this->travel(4)->days();
    expect(ReconnectReminders::run())->toBe(1);
    Mail::assertQueued(ReconnectChannel::class, fn (ReconnectChannel $m): bool => $m->daysLeft === 1);
    Mail::assertNotQueued(ReconnectChannel::class, fn (ReconnectChannel $m): bool => $m->hasTo($this->owner->email));
});

it('says so the day a connection drops, and not for one that renews itself', function () {
    $this->channel->forceFill(['status' => Status::TokenExpired])->save();
    SocialAccount::factory()->create(['workspace_id' => $this->ownerWs->id, 'platform' => Platform::LinkedIn, 'token_expires_at' => now()->addDays(2)]);

    expect(ReconnectReminders::run())->toBe(1);
    Mail::assertQueued(ReconnectChannel::class, fn (ReconnectChannel $m): bool => $m->state === 'dropped' && $m->hasTo($this->mate->email));
});

it('shows the channel in the inbox and the button switches workspace on the way to LinkedIn', function () {
    $own = SocialAccount::factory()->create([
        'workspace_id' => $this->ownerWs->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adi',
        'refresh_token' => null, 'token_expires_at' => now()->addDays(3)->addHour(),
    ]);

    $this->actingAs($this->owner)->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page->has('reconnect', 1)
            ->where('reconnect.0.id', $own->id)->where('reconnect.0.state', 'expiring')->where('reconnect.0.days_left', 3));

    $this->actingAs($this->mate)->get(route('tantra.reconnect', $this->channel))
        ->assertRedirect(route('app.social.linkedin.connect'));
    expect($this->mate->refresh()->current_workspace_id)->toBe($this->mateWs->id);

    // Someone outside the workspace gets nothing.
    $stranger = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->actingAs($stranger)->get(route('tantra.reconnect', $this->channel))->assertNotFound();
});
