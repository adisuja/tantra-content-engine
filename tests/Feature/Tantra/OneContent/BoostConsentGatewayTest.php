<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/GatewayHelpers.php';

// tantra: One Content app PRD 3.5, boost consent at join over the gateway, and its backfill.

beforeEach(function () {
    Cache::flush();
    oneGatewaySetUp($this);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->owner = $result['user'];
    $this->owner->forceFill(['clerk_user_id' => ONE_GW_CLERK])->save();
    $this->member = User::factory()->create(['account_id' => $this->owner->account_id, 'clerk_user_id' => 'user_MemberJoined000000001']);
});

it('stores consent at join and turning it off as an opt out, never a null consent', function () {
    $path = '/api/gateway/tantra/team/members/user_MemberJoined000000001/boost';

    oneGatewayCall($this, 'PUT', $path, ['consent' => true])->assertOk()
        ->assertJsonPath('data.member', 'user_MemberJoined000000001')
        ->assertJsonPath('data.status', 'no_linkedin');
    $profile = MemberProfile::query()->findOrFail($this->member->id);
    expect($profile->boost_consent_at)->not->toBeNull()->and($profile->boost_opted_out_at)->toBeNull();

    oneGatewayCall($this, 'PUT', $path, ['consent' => false])->assertOk();
    $profile->refresh();
    expect($profile->boost_consent_at)->toBeNull()->and($profile->boost_opted_out_at)->not->toBeNull();
});

it('links the member LinkedIn through Unipile and reports each status with the blocker reason', function () {
    $base = '/api/gateway/tantra/team/members/user_MemberJoined000000001';

    oneGatewayCall($this, 'GET', $base.'/status')->assertOk()
        ->assertJsonPath('data.status', 'no_linkedin')->assertJsonPath('data.blocker', 'no confirmed Unipile account');

    oneGatewayCall($this, 'POST', $base.'/match', ['unipile_account_id' => 'acc_member', 'name' => 'Member Name'])->assertOk()
        ->assertJsonPath('data.linkedin.unipile_account_id', 'acc_member')
        ->assertJsonPath('data.status', 'no_consent')
        ->assertJsonPath('data.blocker', 'no consent to post in their name');
    expect(MemberProfile::query()->findOrFail($this->member->id)->unipile_confirmed_at)->not->toBeNull()
        ->and(MemberProfile::query()->findOrFail($this->member->id)->unipile_confirmed_by)->toBe($this->owner->id);

    oneGatewayCall($this, 'PUT', $base.'/boost', ['consent' => true])->assertJsonPath('data.status', 'ready')->assertJsonPath('data.blocker', null);

    $until = now()->addDays(3)->toIso8601ZuluString();
    oneGatewayCall($this, 'PUT', $base.'/boost', ['consent' => true, 'paused_until' => $until])
        ->assertJsonPath('data.status', 'paused_member')->assertJsonPath('data.blocker', 'paused by the member');

    oneGatewayCall($this, 'PUT', $base.'/boost', ['consent' => true, 'paused_until' => null])->assertJsonPath('data.status', 'ready');

    MemberProfile::query()->whereKey($this->member->id)->update(['boost_paused_until' => now()->addHours(72)]);
    oneGatewayCall($this, 'GET', $base.'/status')->assertJsonPath('data.status', 'paused_restriction')
        ->assertJsonPath('data.blocker', 'paused after a LinkedIn restriction');
});

it('refuses a LinkedIn account already linked to another member', function () {
    MemberProfile::query()->create(['user_id' => $this->owner->id, 'unipile_account_id' => 'acc_taken', 'unipile_confirmed_at' => now()]);

    oneGatewayCall($this, 'POST', '/api/gateway/tantra/team/members/user_MemberJoined000000001/match', ['unipile_account_id' => 'acc_taken'])
        ->assertStatus(409);
});

it('finds members only in the acting Account, and says when one never signed in', function () {
    User::factory()->create(['clerk_user_id' => 'user_OtherAccount00000000001']);

    oneGatewayCall($this, 'GET', '/api/gateway/tantra/team/members/user_OtherAccount00000000001/status')->assertNotFound()
        ->assertJsonPath('code', 'no_engine_user');
    oneGatewayCall($this, 'PUT', '/api/gateway/tantra/team/members/user_NeverSignedIn000000001/boost', ['consent' => true])->assertNotFound();
});

it('needs the write scope to change consent', function () {
    oneGatewayCall($this, 'PUT', '/api/gateway/tantra/team/members/user_MemberJoined000000001/boost', ['consent' => true], scopes: 'content:read')
        ->assertForbidden();
});

it('backfills consent only for members with neither consent nor an opt out', function () {
    $optedOut = User::factory()->create(['account_id' => $this->owner->account_id, 'clerk_user_id' => 'user_OptedOut0000000000001']);
    $undecided = User::factory()->create(['account_id' => $this->owner->account_id, 'clerk_user_id' => 'user_Undecided000000000001']);
    $consented = User::factory()->create(['account_id' => $this->owner->account_id, 'clerk_user_id' => 'user_Consented000000000001']);
    MemberProfile::query()->create(['user_id' => $optedOut->id, 'boost_opted_out_at' => now()->subDay()]);
    MemberProfile::query()->create(['user_id' => $undecided->id]);
    $before = now()->subWeek()->startOfSecond();
    MemberProfile::query()->create(['user_id' => $consented->id, 'boost_consent_at' => $before]);

    $this->artisan('tantra:boost-consent-backfill', ['--dry-run' => true])->assertSuccessful();
    expect(MemberProfile::query()->findOrFail($undecided->id)->boost_consent_at)->toBeNull();

    $this->artisan('tantra:boost-consent-backfill')->assertSuccessful();

    expect(MemberProfile::query()->findOrFail($optedOut->id)->boost_consent_at)->toBeNull()
        ->and(MemberProfile::query()->findOrFail($undecided->id)->boost_consent_at)->not->toBeNull()
        ->and(MemberProfile::query()->findOrFail($consented->id)->boost_consent_at->equalTo($before))->toBeTrue()
        // A Tantra member with no profile yet gets one, with consent.
        ->and(MemberProfile::query()->findOrFail($this->member->id)->boost_consent_at)->not->toBeNull();
});
