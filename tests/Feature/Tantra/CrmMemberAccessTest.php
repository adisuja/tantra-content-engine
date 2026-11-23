<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PublishPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Provision\TantraWorkspaceLink;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

// tantra: M1 open access (PRD_2026_11_23 section 2.4, decision 8). A teammate the Tantra app names joins the mapped workspace as a
// member, every member connects channels and publishes, and team management, webhooks and billing stay with admins and the owner.

beforeEach(function () {
    Notification::fake();
    Bus::fake([PublishPost::class]);
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    $this->second = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Aquila']);
    $this->member = workspaceMember($this->team, 'member', ['email' => 'mo@example.test']);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adi Murali', 'status' => Status::Connected]);
    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id}|{$this->second->id}",
    ]);
});

function m1Call(object $test, string $path, array $body): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer crm-key', 'X-Tantra-Client' => 'crm-content'])->postJson("/api/tantra/engagement/{$path}", $body);
}

it('joins an unknown teammate to the first mapped workspace as a member, once', function () {
    $user = CrmContent::actorInTeam([$this->team->id, $this->second->id], ' Priya.Raman@Example.test ')[1];
    $again = CrmContent::actorInTeam([$this->team->id, $this->second->id], 'priya.raman@example.test')[1];

    $pivot = $this->team->members()->whereKey($user->id)->first()?->pivot;
    expect($user->email)->toBe('priya.raman@example.test')
        ->and($user->name)->toBe('Priya Raman')
        ->and($user->account_id)->toBe($this->team->account_id)
        ->and($again->id)->toBe($user->id)
        ->and((bool) $pivot->is_admin)->toBeFalse()
        ->and((bool) $pivot->requires_approval)->toBeFalse()
        ->and($this->second->members()->whereKey($user->id)->exists())->toBeFalse();
});

it('lets a teammate the Engine did not know publish straight away, without approval', function () {
    m1Call($this, 'publish', [
        'workspace' => 'ws_team', 'actor_email' => 'new@example.test', 'channels' => [$this->linkedin->id],
        'content' => 'Our December webinar is open.', 'mode' => 'schedule', 'scheduled_at' => now()->addDays(2)->utc()->toIso8601String(),
    ])->assertCreated()->assertJsonPath('approval', false);

    expect(User::query()->where('email', 'new@example.test')->sole()->can('manageAccounts', $this->team))->toBeTrue();
});

it('gives a member and a new teammate a connect link, and keeps team management and billing with admins and the owner', function () {
    $body = ['workspace' => 'ws_team', 'network' => 'x', 'return_url' => 'https://crm.usetantra.com/content/invite?connected=social'];
    foreach (['mo@example.test', 'fresh@example.test'] as $email) {
        expect(m1Call($this, 'publish/connect', [...$body, 'actor_email' => $email])->assertOk()->json('data.url'))->toContain('signature=');
    }

    $fresh = User::query()->where('email', 'fresh@example.test')->sole();
    foreach ([$this->member, $fresh] as $person) {
        expect($person->can('manageAccounts', $this->team))->toBeTrue()
            ->and($person->can('publishDirectly', $this->team))->toBeTrue()
            ->and($person->can('approvePosts', $this->team))->toBeTrue()
            ->and($person->can('manageRepurposes', $this->team))->toBeTrue()
            ->and($person->can('manageTeam', $this->team))->toBeFalse()
            ->and($person->can('inviteMember', $this->team))->toBeFalse()
            ->and($person->can('manageWebhooks', $this->team))->toBeFalse()
            ->and($person->can('manageBilling', $this->team))->toBeFalse();
    }
});

it('reads Content settings for a teammate it did not know, as an editor who needs no approval', function () {
    m1Call($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'late@example.test'])
        ->assertOk()->assertJsonPath('data.you.role', 'editor')->assertJsonPath('data.you.needs_approval', false);
});

it('backfills requires_approval off for members of Tantra mapped workspaces only, idempotently', function () {
    $asker = workspaceMember($this->team, 'approval', ['email' => 'ann@example.test']);
    $linked = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_linked', 'workspace_id' => $linked->id, 'account_id' => $linked->account_id, 'name' => 'Linked', 'made_workspace' => false]);
    $linkedAsker = workspaceMember($linked, 'approval');
    $outside = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $outsideAsker = workspaceMember($outside, 'approval');

    $this->artisan('tantra:members-open-access', ['--dry-run' => true])->assertSuccessful();
    expect($asker->requiresApprovalIn($this->team))->toBeTrue();

    $this->artisan('tantra:members-open-access')->assertSuccessful();
    $this->artisan('tantra:members-open-access')->assertSuccessful()->expectsOutputToContain('0 member(s)');

    expect($asker->fresh()->requiresApprovalIn($this->team))->toBeFalse()
        ->and($linkedAsker->fresh()->requiresApprovalIn($linked))->toBeFalse()
        ->and($outsideAsker->fresh()->requiresApprovalIn($outside))->toBeTrue()
        ->and($asker->fresh()->isWorkspaceAdmin($this->team))->toBeFalse();
});
