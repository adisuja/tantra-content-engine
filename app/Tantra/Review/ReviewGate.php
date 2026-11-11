<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Models\User;
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
        return WorkspaceSettings::query()->find($item->workspace_id)?->review_owner_user_id
            ?? $item->workspace?->account?->owner_id;
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

    public static function canSign(User $user, ReviewItem $item, string $signer): bool
    {
        return $signer === 'adi' ? self::isAccountOwner($user, $item) : self::isReviewOwner($user, $item);
    }
}
