<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Auth\ClerkLogin;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Engagement\EngagementChannels;
use App\Tantra\Provision\TantraWorkspaceLink;
use App\Tantra\Provision\TantraWorkspacePerson;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

// tantra: PV-02, POST /api/tantra/provision and what reads the link (tantra-crm docs/crm/engine-patches/pv-02/README.md).

beforeEach(function () {
    config(['tantra.engagement_channels.key' => 'prov-key', 'tantra.engagement_channels.workspaces' => '']);
});

function provPeople(): array
{
    return [
        ['clerk_user_id' => 'user_own', 'email' => 'Owner@Acme.test', 'name' => 'Olive Owner', 'role' => 'owner'],
        ['clerk_user_id' => 'user_adm', 'email' => 'admin@acme.test', 'name' => 'Adam Admin', 'role' => 'admin'],
        ['clerk_user_id' => 'user_mem', 'email' => 'member@acme.test', 'name' => 'Mia Member', 'role' => 'member'],
    ];
}

function provCall(object $test, ?array $body = null, ?string $key = 'prov-key'): TestResponse
{
    $body ??= ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => provPeople()];

    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}"]))->postJson('/api/tantra/provision', $body);
}

function provUser(string $clerkId): User
{
    return User::query()->where('clerk_user_id', $clerkId)->firstOrFail();
}

it('refuses a missing or wrong key and creates nothing', function () {
    provCall($this, key: null)->assertUnauthorized();
    provCall($this, key: 'wrong')->assertUnauthorized();

    expect(TantraWorkspaceLink::query()->count())->toBe(0)->and(Workspace::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
});

it('makes a workspace, users and roles for a new team without sending mail', function () {
    Mail::fake();

    $r = provCall($this)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['ok' => true, 'created' => true, 'people' => ['added' => 3, 'removed' => 0, 'kept' => 0, 'skipped' => 0]]);

    $link = TantraWorkspaceLink::query()->where('tantra_workspace_id', 'ws_acme')->firstOrFail();
    $workspace = Workspace::query()->findOrFail($link->workspace_id);
    $owner = provUser('user_own');
    expect(Workspace::query()->count())->toBe(1)
        ->and($workspace->name)->toBe('Acme')
        ->and($workspace->user_id)->toBe($owner->id)
        ->and($owner->email)->toBe('owner@acme.test')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->account_id)->toBe($workspace->account_id);

    $admin = provUser('user_adm');
    $member = provUser('user_mem');
    expect($admin->accessIn($workspace)['is_admin'])->toBeTrue()
        ->and($member->accessIn($workspace)['is_admin'])->toBeFalse()
        ->and($member->workspaces()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and(TantraWorkspacePerson::query()->count())->toBe(3);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    expect($r->getContent())->not->toContain($workspace->id)->not->toContain('owner@acme.test');
});

it('changes nothing when the same body arrives twice', function () {
    provCall($this)->assertOk();
    provCall($this)->assertOk()->assertJson(['created' => false, 'people' => ['added' => 0, 'removed' => 0, 'kept' => 3, 'skipped' => 0]]);

    expect(Workspace::query()->count())->toBe(1)->and(User::query()->count())->toBe(3)->and(TantraWorkspaceLink::query()->count())->toBe(1);
});

it('links a hand made engine user by email instead of making a second one', function () {
    $hand = User::factory()->create(['email' => 'member@acme.test', 'clerk_user_id' => null, 'account_id' => null]);

    provCall($this)->assertOk()->assertJson(['people' => ['added' => 3, 'skipped' => 0]]);

    expect(User::query()->whereRaw('lower(email) = ?', ['member@acme.test'])->count())->toBe(1)->and($hand->fresh()->clerk_user_id)->toBe('user_mem');
});

it('never takes a user by email when it is linked to another Clerk id', function () {
    $other = User::factory()->create(['email' => 'member@acme.test', 'clerk_user_id' => 'user_someone_else']);

    provCall($this)->assertOk()->assertJson(['people' => ['added' => 2, 'skipped' => 1]]);

    $workspace = Workspace::query()->firstOrFail();
    expect($other->fresh()->clerk_user_id)->toBe('user_someone_else')->and($workspace->members()->whereKey($other->id)->exists())->toBeFalse();
});

it('links a team already in the env map to its first mapped workspace and creates none', function () {
    $keeper = User::factory()->create();
    $live = Workspace::factory()->create(['account_id' => $keeper->account_id, 'user_id' => $keeper->id]);
    $second = Workspace::factory()->create(['account_id' => $keeper->account_id, 'user_id' => $keeper->id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $live->id, 'platform' => Platform::Bluesky, 'status' => Status::Connected]);
    config(['tantra.engagement_channels.workspaces' => "ws_acme={$live->id}|{$second->id}"]);
    $before = Workspace::query()->count();

    provCall($this, ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => [['clerk_user_id' => 'user_adm', 'email' => 'admin@acme.test', 'name' => 'Adam', 'role' => 'admin']]])
        ->assertOk()->assertJson(['created' => false]);

    expect(Workspace::query()->count())->toBe($before)
        ->and(TantraWorkspaceLink::query()->firstOrFail()->workspace_id)->toBe($live->id)
        ->and($live->fresh()->name)->toBe($live->name)
        ->and($channel->fresh()->workspace_id)->toBe($live->id);
});

it('removes only what provisioning added when a person leaves', function () {
    provCall($this)->assertOk();
    $workspace = Workspace::query()->firstOrFail();
    $hand = workspaceMember($workspace, 'member', ['email' => 'hand@acme.test']);
    $people = provPeople();
    array_pop($people);

    provCall($this, ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => $people])
        ->assertOk()->assertJson(['people' => ['added' => 0, 'removed' => 1, 'kept' => 2]]);

    $left = provUser('user_mem');
    expect($left->workspaces()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($left->id)->exists())->toBeTrue()
        ->and(TantraWorkspacePerson::query()->where('clerk_user_id', 'user_mem')->exists())->toBeFalse()
        ->and($hand->workspaces()->whereKey($workspace->id)->exists())->toBeTrue();
});

it('moves ownership to a new owner and keeps the old one as admin when still listed', function () {
    provCall($this)->assertOk();
    $workspace = Workspace::query()->firstOrFail();
    $people = [
        ['clerk_user_id' => 'user_own', 'email' => 'owner@acme.test', 'name' => 'Olive', 'role' => 'admin'],
        ['clerk_user_id' => 'user_adm', 'email' => 'admin@acme.test', 'name' => 'Adam', 'role' => 'owner'],
        ['clerk_user_id' => 'user_mem', 'email' => 'member@acme.test', 'name' => 'Mia', 'role' => 'member'],
    ];

    provCall($this, ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => $people])->assertOk()->assertJson(['created' => false]);

    $workspace->refresh();
    expect($workspace->user_id)->toBe(provUser('user_adm')->id)
        ->and(provUser('user_own')->accessIn($workspace)['is_admin'])->toBeTrue()
        ->and(provUser('user_own')->workspaces()->whereKey($workspace->id)->exists())->toBeTrue();
});

it('refuses a new team with no owner and bad bodies, creating nothing', function () {
    $noOwner = [['clerk_user_id' => 'user_adm', 'email' => 'admin@acme.test', 'name' => 'A', 'role' => 'admin']];
    provCall($this, ['workspace' => 'ws_x', 'name' => 'X', 'people' => $noOwner])->assertStatus(422)->assertJson(['code' => 'no_owner']);

    $two = [...provPeople(), ['clerk_user_id' => 'user_two', 'email' => 'two@acme.test', 'name' => 'T', 'role' => 'owner']];
    provCall($this, ['workspace' => 'ws_x', 'name' => 'X', 'people' => $two])->assertStatus(422)->assertJson(['code' => 'validation']);

    $role = provPeople();
    $role[1]['role'] = 'god';
    provCall($this, ['workspace' => 'ws_x', 'name' => 'X', 'people' => $role])->assertStatus(422)->assertJson(['code' => 'validation']);

    $email = provPeople();
    $email[1]['email'] = 'not-an-email';
    provCall($this, ['workspace' => 'ws_x', 'name' => 'X', 'people' => $email])->assertStatus(422)->assertJson(['code' => 'validation']);

    $dup = provPeople();
    $dup[1]['clerk_user_id'] = 'user_own';
    provCall($this, ['workspace' => 'ws_x', 'name' => 'X', 'people' => $dup])->assertStatus(422)->assertJson(['code' => 'validation']);

    expect(TantraWorkspaceLink::query()->count())->toBe(0)->and(Workspace::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
});

it('reads the link first, then the env map, then nothing', function () {
    $keeper = User::factory()->create();
    $mapped = Workspace::factory()->create(['account_id' => $keeper->account_id, 'user_id' => $keeper->id]);
    $linked = Workspace::factory()->create(['account_id' => $keeper->account_id, 'user_id' => $keeper->id]);
    config(['tantra.engagement_channels.workspaces' => "ws_env={$mapped->id},ws_both={$mapped->id}"]);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_link', 'workspace_id' => $linked->id, 'name' => 'L']);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_both', 'workspace_id' => $linked->id, 'name' => 'B']);

    expect(EngagementChannels::engineWorkspaces('ws_link'))->toBe([$linked->id])
        ->and(EngagementChannels::engineWorkspaces('ws_both'))->toBe([$linked->id])
        ->and(EngagementChannels::engineWorkspaces('ws_env'))->toBe([$mapped->id])
        ->and(EngagementChannels::engineWorkspaces('ws_unknown'))->toBe([])
        ->and(CrmContent::workspaceIds('ws_link'))->toBe([$linked->id]);

    SocialAccount::factory()->create(['workspace_id' => $linked->id, 'platform' => Platform::Bluesky, 'status' => Status::Connected]);
    SocialAccount::factory()->create(['workspace_id' => $mapped->id, 'platform' => Platform::Bluesky, 'status' => Status::Connected]);
    expect(EngagementChannels::accounts(EngagementChannels::engineWorkspaces('ws_link'), null, false))->toHaveCount(1);
});

it('makes the engine user on first sign in for a person on a provisioned team and refuses strangers', function () {
    provCall($this)->assertOk();
    $workspace = Workspace::query()->firstOrFail();
    $gone = provUser('user_mem');
    $gone->delete();
    expect(TantraWorkspacePerson::query()->where('clerk_user_id', 'user_mem')->value('user_id'))->toBeNull();

    $claims = ['sub' => 'user_mem', 'email' => 'member@acme.test', 'email_verified' => true];
    $result = ClerkLogin::resolve($claims);

    expect($result['user'])->not->toBeNull()
        ->and($result['user']->clerk_user_id)->toBe('user_mem')
        ->and($result['user']->workspaces()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and($result['user']->accessIn($workspace)['is_admin'])->toBeFalse()
        ->and(TantraWorkspacePerson::query()->where('clerk_user_id', 'user_mem')->value('user_id'))->toBe($result['user']->id);

    $stranger = ClerkLogin::resolve(['sub' => 'user_nobody', 'email' => 'nobody@else.test', 'email_verified' => true]);
    expect($stranger['user'])->toBeNull()->and(User::query()->where('clerk_user_id', 'user_nobody')->exists())->toBeFalse();
});

it('answers with counts only, never an engine id, a token or an email', function () {
    $r = provCall($this)->assertOk();

    $r->assertJsonMissingPath('workspace_id')->assertJsonMissingPath('id')->assertJsonMissingPath('token')->assertJsonMissingPath('email');
    expect(array_keys($r->json()))->toEqualCanonicalizing(['ok', 'created', 'people'])
        ->and($r->getContent())->not->toContain(Workspace::query()->firstOrFail()->id)->not->toContain('@')->not->toContain('prov-key');
});

it('makes one workspace for two calls about the same new team', function () {
    provCall($this)->assertOk()->assertJson(['created' => true]);
    provCall($this)->assertOk()->assertJson(['created' => false]);

    expect(Workspace::query()->count())->toBe(1)->and(TantraWorkspaceLink::query()->count())->toBe(1);
});

// tantra: AF-04, the Tantra workspace's time zone replaces the engine default.
it('stores the Tantra time zone on the workspace and gives it to people who still have the engine default', function () {
    provCall($this, ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => provPeople(), 'timezone' => 'Europe/Berlin'])->assertOk();

    $link = TantraWorkspaceLink::query()->where('tantra_workspace_id', 'ws_acme')->firstOrFail();
    expect(\App\Tantra\Review\Models\WorkspaceSettings::query()->find($link->workspace_id)?->timezone)->toBe('Europe/Berlin')
        ->and(provUser('user_own')->timezone)->toBe('Europe/Berlin')
        ->and(provUser('user_mem')->timezone)->toBe('Europe/Berlin');

    $chosen = provUser('user_adm');
    $chosen->forceFill(['timezone' => 'America/Chicago'])->save();
    provCall($this, ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => provPeople(), 'timezone' => 'Europe/Paris'])->assertOk();

    expect($chosen->refresh()->timezone)->toBe('America/Chicago')
        ->and(provUser('user_own')->timezone)->toBe('Europe/Berlin');
});

it('refuses a time zone that is not real', function () {
    provCall($this, ['workspace' => 'ws_acme', 'name' => 'Acme', 'people' => provPeople(), 'timezone' => 'Mars/Olympus'])->assertStatus(422);
});
