<?php

declare(strict_types=1);

namespace App\Tantra\Review\Enums;

enum ReviewStatus: string
{
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /** The engine may replace copy and media while the item is in one of these. */
    public function isEditableByEngine(): bool
    {
        return in_array($this, [self::InReview, self::ChangesRequested], true);
    }

    /** Waiting on the review owner. */
    public function isWaiting(): bool
    {
        return in_array($this, [self::InReview, self::ChangesRequested], true);
    }
}
