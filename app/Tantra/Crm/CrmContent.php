<?php

declare(strict_types=1);

namespace App\Tantra\Crm;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Engagement\EngagementChannels;

/**
 * tantra: what the Tantra app's native Content screens (Ideas, Drafts, Calendar) share (tantra-crm
 * docs/crm/PRD_CONTENT_NATIVE.md). A Tantra team id maps explicitly to Engine workspaces (ENGINE_CHANNELS_WORKSPACES),
 * an unmapped team gets nothing and never a default workspace, and the person acting is found by email inside the post's workspace.
 */
final class CrmContent
{
    /**
     * The Engine workspaces this Tantra team maps to.
     *
     * @return list<string>
     */
    public static function workspaceIds(?string $crmWorkspace): array
    {
        return EngagementChannels::engineWorkspaces($crmWorkspace);
    }

    /** The person with this email who belongs to the workspace (its owner or a member), or null. */
    public static function actor(Workspace $workspace, ?string $email): ?User
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        return User::query()->whereRaw('lower(email) = ?', [$email])->get()
            ->first(fn (User $user): bool => $workspace->user_id === $user->id || $workspace->members()->whereKey($user->id)->exists());
    }

    /** @param \Illuminate\Pagination\LengthAwarePaginator<int, mixed> $page */
    public static function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
