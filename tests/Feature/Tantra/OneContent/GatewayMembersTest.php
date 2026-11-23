<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Gateway\GatewaySignature;
use App\Tantra\Kit\PostKit;
use App\Tantra\Provision\TantraWorkspaceLink;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/GatewayHelpers.php';

// tantra: M2, the gateway is open to every team member (PRD 2026-11-23 decision 8). Team management stays with admins.

const M2_MEMBER = 'user_PlainTeamMember0000001';

beforeEach(function () {
    Cache::flush();
    oneGatewaySetUp($this);
    config(['tantra.crm.intake_key' => null, 'tantra.crm.url' => 'https://crm.test']);

    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    $this->owner->forceFill(['clerk_user_id' => ONE_GW_CLERK])->save();
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_crm_team', 'workspace_id' => $this->ws->id, 'account_id' => $this->owner->account_id, 'name' => 'Team']);

    SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_m2']);
    $this->member = workspaceMember($this->ws, 'member', ['clerk_user_id' => M2_MEMBER]);

    ImportBatch::execute($this->owner, ['engine_batch_id' => '41', 'posts' => [[
        'engine_post_id' => 'M2P1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-12-01', 'time' => '08:30',
        'tz' => 'UTC', 'text' => 'The post.', 'first_comment' => 'Link in here.',
    ]]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'M2P1')->sole();
});

it('lets a plain member who is not the review owner read Review, save a thread, approve, unapprove and reject through the gateway', function () {
    expect($this->member->can('manageTeam', $this->ws))->toBeFalse();

    oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', member: M2_MEMBER)->assertOk();
    oneGatewayCall($this, 'GET', '/api/gateway/workspace', member: M2_MEMBER)->assertOk();

    // M2: the CRM applies the team's approver rule before it calls, so the engine's review owner rule is not applied here.
    expect($this->member->id)->not->toBe(WorkspaceSettings::query()->findOrFail($this->ws->id)->review_owner_user_id);
    $path = "/api/gateway/tantra/posts/{$this->item->id}/thread";
    oneGatewayCall($this, 'GET', $path, member: M2_MEMBER)->assertOk();
    oneGatewayCall($this, 'PUT', $path, ['first_comment' => 'Member edit.'], ['If-Match' => PostKit::etag($this->item)], member: M2_MEMBER)
        ->assertOk();
    expect(data_get($this->item->refresh()->payload, 'first_comment'))->toBe('Member edit.');

    $row = collect(oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', member: M2_MEMBER)->json('data.items') ?? [])->firstWhere('ref', $this->item->id);
    expect($row)->not->toBeNull()->and($row['can_approve'])->toBeTrue();

    oneGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/approve", member: M2_MEMBER)->assertOk();
    expect($this->item->refresh()->review_status)->not->toBe(ReviewStatus::InReview);
    oneGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/unapprove", member: M2_MEMBER)->assertOk();
    oneGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/reject", member: M2_MEMBER)->assertOk();
    expect($this->item->refresh()->review_status)->toBe(ReviewStatus::Rejected);
});

it('lets a member pin a workspace they belong to, but not one they do not', function () {
    $pin = ['X-Tantra-Content-Workspace' => (string) $this->ws->id];
    oneGatewayCall($this, 'GET', '/api/gateway/workspace', headers: $pin, member: M2_MEMBER)->assertOk();

    $stranger = createApiTestToken();
    oneGatewayCall($this, 'GET', '/api/gateway/workspace', headers: ['X-Tantra-Content-Workspace' => (string) $stranger['workspace']->id], member: M2_MEMBER)
        ->assertForbidden()->assertJsonPath('code', 'no_workspace');
});

it('lets a member change their own boost but not another member\'s', function () {
    $mate = workspaceMember($this->ws, 'member', ['clerk_user_id' => 'user_OtherTeamMember000001']);

    oneGatewayCall($this, 'PUT', '/api/gateway/tantra/team/members/'.M2_MEMBER.'/boost', ['consent' => true], member: M2_MEMBER)->assertOk();
    expect(MemberProfile::query()->findOrFail($this->member->id)->boost_consent_at)->not->toBeNull();

    oneGatewayCall($this, 'PUT', '/api/gateway/tantra/team/members/user_OtherTeamMember000001/boost', ['consent' => false], member: M2_MEMBER)
        ->assertForbidden()->assertJsonPath('code', 'forbidden');
    oneGatewayCall($this, 'POST', '/api/gateway/tantra/team/members/user_OtherTeamMember000001/match', ['unipile_account_id' => 'acc_x'], member: M2_MEMBER)
        ->assertForbidden();
    // A teammate the engine never saw cannot be joined by a member either.
    oneGatewayCall($this, 'PUT', '/api/gateway/tantra/team/members/user_NeverSeenMate00000001/boost', ['consent' => true, 'email' => 'x@example.test'], member: M2_MEMBER)
        ->assertForbidden();
    expect(MemberProfile::query()->find($mate->id))->toBeNull()
        ->and(User::query()->where('clerk_user_id', 'user_NeverSeenMate00000001')->exists())->toBeFalse();

    // Reading a status stays open, and an admin may change anyone's.
    oneGatewayCall($this, 'GET', '/api/gateway/tantra/team/members/user_OtherTeamMember000001/status', member: M2_MEMBER)->assertOk();
    oneGatewayCall($this, 'PUT', '/api/gateway/tantra/team/members/user_OtherTeamMember000001/boost', ['consent' => true])->assertOk();
});

it('keeps admin-only routes refusing members', function () {
    oneGatewayCall($this, 'GET', '/api/gateway/webhooks', member: M2_MEMBER)->assertForbidden();
    oneGatewayCall($this, 'GET', '/api/gateway/webhooks')->assertOk();
});

it('sets up a teammate the engine never saw from the signed email, in the team\'s linked workspace', function () {
    $clerk = 'user_BrandNewTeammate00001';

    oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', member: $clerk, email: 'New.Teammate@Example.test')->assertOk();

    $user = User::query()->where('clerk_user_id', $clerk)->sole();
    $pivot = $this->ws->members()->whereKey($user->id)->first()?->pivot;
    expect($user->email)->toBe('new.teammate@example.test')
        ->and($user->account_id)->toBe($this->owner->account_id)
        ->and($user->current_workspace_id)->toBe($this->ws->id)
        ->and((bool) $pivot->is_admin)->toBeFalse();

    // Pinned to the linked workspace works the same, and a second call makes nobody new.
    oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', headers: ['X-Tantra-Content-Workspace' => (string) $this->ws->id], member: $clerk, email: 'new.teammate@example.test')->assertOk();
    expect(User::query()->whereRaw('lower(email) = ?', ['new.teammate@example.test'])->count())->toBe(1);
});

it('does not set up an unknown teammate without a signed email, or for a workspace the team is not linked to', function () {
    $words = 'Your Tantra account is being linked to Content. Try again in a minute.';

    oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', member: 'user_NoEmailTeammate000001')
        ->assertForbidden()->assertJsonPath('code', 'no_engine_access')->assertJsonPath('message', $words);

    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', headers: ['X-Tantra-Content-Workspace' => (string) $other->id], member: 'user_WrongPinTeammate00001', email: 'pin@example.test')
        ->assertForbidden()->assertJsonPath('code', 'no_engine_access');

    // An email header the Worker did not sign is a forged call.
    $sig = GatewaySignature::sign(ONE_GW_SECRET, 'k1', time(), 'ws_crm_team', 'user_ForgedEmailMate00001', 'content:read', 'GET', '/api/gateway/tantra/review', '');
    $this->call('GET', '/api/gateway/tantra/review', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TANTRA_SIGNATURE' => $sig, 'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team',
        'HTTP_X_TANTRA_MEMBER' => 'user_ForgedEmailMate00001', 'HTTP_X_TANTRA_SCOPES' => 'content:read', 'HTTP_X_TANTRA_MEMBER_EMAIL' => 'forged@example.test',
    ])->assertUnauthorized();

    // An email held by another Account never joins this team.
    User::factory()->create(['email' => 'elsewhere@example.test']);
    oneGatewayCall($this, 'GET', '/api/gateway/tantra/review', member: 'user_ElsewhereMate0000001', email: 'elsewhere@example.test')
        ->assertForbidden()->assertJsonPath('code', 'no_engine_access');

    expect(User::query()->whereIn('clerk_user_id', ['user_NoEmailTeammate000001', 'user_WrongPinTeammate00001', 'user_ForgedEmailMate00001', 'user_ElsewhereMate0000001'])->exists())->toBeFalse();
});
