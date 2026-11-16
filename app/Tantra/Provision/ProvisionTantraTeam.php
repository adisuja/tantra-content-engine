<?php

declare(strict_types=1);

namespace App\Tantra\Provision;

use App\Actions\AccessToken\RevokeMcpOAuthGrants;
use App\Actions\AccessToken\RevokeWorkspaceApiKeys;
use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\Workspace\CreateWorkspace;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Engagement\EngagementChannels;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * tantra: PV-02, one setup for every feature (tantra-crm docs/crm/engine-patches/pv-02/README.md). The Tantra app sends a team as it is
 * now ({workspace, name, people}); this finds or makes the Engine workspace and an Engine user per person, links them by clerk_user_id,
 * maps the roles and removes only what provisioning added. Idempotent: the same team twice changes nothing.
 */
final class ProvisionTantraTeam
{
    /** Role => pivot on user_workspace. */
    private const PIVOT = [
        'owner' => ['is_admin' => true, 'requires_approval' => false],
        'admin' => ['is_admin' => true, 'requires_approval' => false],
        'member' => ['is_admin' => false, 'requires_approval' => false],
    ];

    /**
     * @param  list<array{clerk_user_id: string, email: string, name?: ?string, role: string}>  $people
     * @return array{created: bool, people: array{added: int, removed: int, kept: int, skipped: int}}
     *
     * @throws NoOwner
     */
    public static function execute(string $tantraWorkspace, string $name, array $people): array
    {
        try {
            return DB::transaction(fn (): array => (new self)->run($tantraWorkspace, $name, $people));
        } catch (UniqueConstraintViolationException) {
            // Two first calls for the same team raced: the unique index let one win, so this one reads its link.
            return DB::transaction(fn (): array => (new self)->run($tantraWorkspace, $name, $people));
        }
    }

    /**
     * The engine user for a Clerk user that is on a provisioned team but has no engine user (ClerkLogin, spec section 4): made
     * here, linked on every such row and added to each workspace with the stored role. Null when that Clerk id is on no team.
     */
    public static function completeLogin(string $clerkId, string $email): ?User
    {
        $rows = TantraWorkspacePerson::query()->where('clerk_user_id', $clerkId)->with('link.workspace')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($rows, $clerkId, $email): ?User {
            $first = $rows->first(fn (TantraWorkspacePerson $r): bool => $r->link?->workspace !== null);
            if ($first === null) {
                return null;
            }
            $account = $first->link->workspace->account;
            $user = $rows->pluck('user_id')->filter()->map(fn (string $id): ?User => User::query()->find($id))->filter()->first()
                ?? self::makeUser($email, Str::before($email, '@'), $clerkId, $account);

            foreach ($rows as $row) {
                $workspace = $row->link?->workspace;
                if ($workspace === null || $workspace->account_id !== $user->account_id) {
                    continue;
                }
                $row->forceFill(['user_id' => $user->id])->save();
                self::attach($workspace, $user, $row->role);
            }

            return $user->refresh();
        });
    }

    /** @param list<array{clerk_user_id: string, email: string, name?: ?string, role: string}> $people */
    private function run(string $tantraWorkspace, string $name, array $people): array
    {
        $counts = ['added' => 0, 'removed' => 0, 'kept' => 0, 'skipped' => 0];
        $created = false;

        $link = TantraWorkspaceLink::query()->where('tantra_workspace_id', $tantraWorkspace)->lockForUpdate()->first();
        $owner = collect($people)->firstWhere('role', 'owner');
        /** @var array<string, User> $users clerk id => user */
        $users = [];

        if ($link === null) {
            $mappedId = EngagementChannels::workspaceMap()[$tantraWorkspace][0] ?? null;
            $mapped = $mappedId !== null && Str::isUuid($mappedId) ? Workspace::query()->find($mappedId) : null;
            if ($mapped !== null) {
                $link = $this->link($tantraWorkspace, $mapped, $name, false);
            } else {
                if ($owner === null) {
                    throw new NoOwner('A new team needs an owner.');
                }
                $ownerUser = $this->resolve($owner, null);
                if ($ownerUser === null) {
                    throw new NoOwner('The team owner could not be set up.');
                }
                if ($ownerUser->account_id === null) {
                    $this->accountFor($ownerUser, $name);
                }
                $workspace = CreateWorkspace::execute($ownerUser->refresh(), ['name' => $name]);
                $link = $this->link($tantraWorkspace, $workspace, $name, true);
                $created = true;
                $users[$owner['clerk_user_id']] = $ownerUser;
            }
        }

        $workspace = $link->workspace()->firstOrFail();
        $link->forceFill(['name' => $name, 'account_id' => $workspace->account_id])->save();
        if ($link->made_workspace && $workspace->name !== $name) {
            $workspace->forceFill(['name' => $name])->save();
        }

        $existing = $link->people()->get()->keyBy('clerk_user_id');
        $sorted = collect($people)->sortBy(fn (array $p): int => $p['role'] === 'owner' ? 0 : 1)->values();
        $listed = [];
        $demoted = null;

        foreach ($sorted as $person) {
            $id = $person['clerk_user_id'];
            $user = $users[$id] ?? $this->resolve($person, $workspace->account_id);
            if ($user === null || ($user->account_id !== null && $user->account_id !== $workspace->account_id)) {
                $counts['skipped']++;

                continue;
            }
            if ($user->account_id === null) {
                $user->forceFill(['account_id' => $workspace->account_id])->save();
            }
            $listed[] = $id;

            $role = $person['role'];
            if ($role === 'owner' && $workspace->user_id !== $user->id) {
                // The engine's workspace hand over: the owner is workspaces.user_id; the previous owner is an admin if still listed.
                $demoted = $workspace->user_id;
                $workspace->forceFill(['user_id' => $user->id])->save();
            } elseif ($demoted !== null && $user->id === $demoted && $role === 'member') {
                $role = 'admin';
            }
            self::attach($workspace, $user, $role);

            $row = $existing->get($id);
            if ($row === null) {
                $link->people()->create(['user_id' => $user->id, 'clerk_user_id' => $id, 'role' => $role]);
                $counts['added']++;
            } else {
                $row->forceFill(['user_id' => $user->id, 'role' => $role])->save();
                $counts['kept']++;
            }
        }

        foreach ($existing as $id => $row) {
            if (in_array($id, $listed, true)) {
                continue;
            }
            $user = $row->user_id === null ? null : User::query()->find($row->user_id);
            if ($user !== null && $workspace->user_id === $user->id) {
                continue; // never the workspace's current owner
            }
            if ($user !== null) {
                $this->detach($workspace, $user);
            }
            $row->delete();
            $counts['removed']++;
        }

        return ['created' => $created, 'people' => $counts];
    }

    /** @param array{clerk_user_id: string, email: string, name?: ?string, role: string} $person */
    private function resolve(array $person, ?string $accountId): ?User
    {
        $clerkId = $person['clerk_user_id'];
        $email = Str::lower(trim($person['email']));
        $label = trim((string) ($person['name'] ?? '')) ?: Str::before($email, '@');

        $user = User::query()->where('clerk_user_id', $clerkId)->first();
        if ($user !== null) {
            return $user;
        }

        $byEmail = User::query()->whereRaw('lower(email) = ?', [$email])->orderBy('created_at')->orderBy('id')->first();
        if ($byEmail !== null) {
            if ($byEmail->clerk_user_id !== null) {
                return null; // held by another Clerk user: never taken by email
            }
            $byEmail->forceFill(['clerk_user_id' => $clerkId])->save();

            return $byEmail;
        }

        $account = $accountId === null ? null : Account::query()->find($accountId);
        $account ??= $this->newAccount($label);

        return self::makeUser($email, $label, $clerkId, $account);
    }

    private function newAccount(string $label): Account
    {
        return Account::query()->forceCreate(['name' => $label."'s Account"]);
    }

    private function accountFor(User $user, string $teamName): Account
    {
        $account = Account::query()->forceCreate(['name' => $teamName]);
        $user->forceFill(['account_id' => $account->id])->save();

        return $account;
    }

    private static function makeUser(string $email, string $name, string $clerkId, ?Account $account): User
    {
        $user = User::query()->create([
            'name' => mb_substr($name, 0, 120),
            'email' => Str::lower($email),
            'password' => Str::random(64),
            'email_verified_at' => now(),
            'account_id' => $account?->id,
            'locale' => Locale::DEFAULT,
            'time_format' => TimeFormat::forLocale(Locale::DEFAULT),
            'week_starts_on' => WeekStart::DEFAULT,
            'timezone' => 'Asia/Kolkata',
        ]);
        $user->forceFill(['clerk_user_id' => $clerkId])->save();
        if ($account !== null && $account->owner_id === null) {
            $account->forceFill(['owner_id' => $user->id])->save();
        }

        return $user;
    }

    private function link(string $tantraWorkspace, Workspace $workspace, string $name, bool $made): TantraWorkspaceLink
    {
        return TantraWorkspaceLink::query()->create([
            'tantra_workspace_id' => $tantraWorkspace,
            'workspace_id' => $workspace->id,
            'account_id' => $workspace->account_id,
            'name' => $name,
            'made_workspace' => $made,
        ]);
    }

    public static function attach(Workspace $workspace, User $user, string $role): void
    {
        $pivot = self::PIVOT[$role] ?? self::PIVOT['member'];
        if ($workspace->members()->whereKey($user->id)->exists()) {
            $workspace->members()->updateExistingPivot($user->id, $pivot);
        } else {
            $workspace->members()->attach($user->id, $pivot);
        }
        if ($user->current_workspace_id === null) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }
    }

    /** The engine's remove-member steps without its stranded-user settlement: the user is kept, they may belong elsewhere. */
    private function detach(Workspace $workspace, User $user): void
    {
        $workspace->members()->detach($user->id);
        RevokeWorkspaceApiKeys::forUserOnWorkspace($user->id, $workspace);
        RevokeMcpOAuthGrants::forUserOnWorkspace($user->id, $workspace);
        $user->refresh();
        if ($user->current_workspace_id === $workspace->id) {
            ReassignCurrentWorkspace::forUserAwayFrom($user, $workspace);
        }
    }
}
