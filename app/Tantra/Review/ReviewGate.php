<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Validation\ValidationException;

/**
 * tantra: who may act on a review item (PRD 4 and 10.2). The workspace's review
 * owner approves; the Account owner signs off numbers tagged `adi`; anyone in
 * the workspace may comment.
 */
final class ReviewGate
{
    public static function reviewOwnerId(ReviewItem $item): ?string
    {
        return self::reviewOwnerIdFor((string) $item->workspace_id, $item->workspace?->account?->owner_id);
    }

    /**
     * The named review owner once they have joined (set a password or signed in
     * with Google or GitHub); until then, and when nobody is named, the Account owner.
     */
    public static function reviewOwnerIdFor(string $workspaceId, ?string $accountOwnerId): ?string
    {
        $named = WorkspaceSettings::query()->find($workspaceId)?->review_owner_user_id;
        if ($named === null || $named === $accountOwnerId) {
            return $named ?? $accountOwnerId;
        }

        $user = User::query()->find($named);

        return $user !== null && self::hasJoined($user) ? $named : $accountOwnerId;
    }

    public static function hasJoined(User $user): bool
    {
        return filled($user->password) || filled($user->google_id) || filled($user->github_id);
    }

    public static function isReviewOwner(User $user, ReviewItem $item): bool
    {
        return $user->id === self::reviewOwnerId($item);
    }

    public static function isAccountOwner(User $user, ReviewItem $item): bool
    {
        return $user->id === $item->workspace?->account?->owner_id;
    }

    public static function canView(User $user, ReviewItem $item): bool
    {
        return $user->account_id === $item->account_id
            && ($user->can('view', $item->workspace) || self::isAccountOwner($user, $item));
    }

    public static function assertReviewOwner(User $user, ReviewItem $item): void
    {
        if (! self::isReviewOwner($user, $item)) {
            throw ValidationException::withMessages(['review' => 'Only the review owner of this workspace can do that.']);
        }
    }

    /**
     * Workspaces whose posts this user approves: the ones naming them as review
     * owner, plus, for the Account owner, every workspace that names nobody.
     *
     * @return list<string>
     */
    public static function reviewedWorkspaceIds(User $user): array
    {
        $ownerId = $user->account?->owner_id;

        return Workspace::query()->where('account_id', $user->account_id)->pluck('id')
            ->filter(fn (string $id): bool => self::reviewOwnerIdFor($id, $ownerId) === $user->id)
            ->values()->all();
    }

    public static function waitingCount(User $user): int
    {
        return ReviewItem::query()
            ->where('account_id', $user->account_id)
            ->whereIn('workspace_id', self::reviewedWorkspaceIds($user))
            ->where('review_status', ReviewStatus::InReview->value)
            ->count();
    }

    public static function canSign(User $user, ReviewItem $item, string $signer): bool
    {
        return $signer === 'adi' ? self::isAccountOwner($user, $item) : self::isReviewOwner($user, $item);
    }
}
