<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace);
    }

    public function create(User $user): bool
    {
        return $user->isAccountOwner();
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    public function manageTeam(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    /**
     * tantra: M1 open access (23 Nov). Every member connects, reorders and disconnects channels; only team
     * management (manageTeam, inviteMember), webhooks and billing stay with admins and the owner.
     */
    public function manageAccounts(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace);
    }

    public function manageWebhooks(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    /** Repurposes publish on the team's channels, so they follow publishDirectly. */
    public function manageRepurposes(User $user, Workspace $workspace): bool
    {
        return $this->publishDirectly($user, $workspace);
    }

    /**
     * Every member may write posts; whether they reach the queue directly is
     * decided by `publishDirectly`.
     */
    public function createPost(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace);
    }

    /**
     * tantra: M1, every member publishes directly (Tantra members join with requires_approval false, and
     * tantra:members-open-access clears it for existing ones). Two rules a team sets for itself stay: a member an
     * admin explicitly marked "needs approval", and the review owner rule (PRD 10.8, OwnerApproval).
     */
    public function publishDirectly(User $user, Workspace $workspace): bool
    {
        return $this->canAccess($user, $workspace) && $user->canPublishDirectlyIn($workspace);
    }

    public function approvePosts(User $user, Workspace $workspace): bool
    {
        return $this->publishDirectly($user, $workspace);
    }

    public function inviteMember(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    private function isOwner(User $user, Workspace $workspace): bool
    {
        return $user->ownsAccountOf($workspace);
    }

    private function isAdmin(User $user, Workspace $workspace): bool
    {
        return $user->isWorkspaceAdmin($workspace);
    }

    private function canAccess(User $user, Workspace $workspace): bool
    {
        if ($workspace->account_id !== $user->account_id) {
            return false;
        }

        if ($user->isAccountOwner()) {
            return true;
        }

        return $workspace->members()->where('user_id', $user->id)->exists();
    }
}
