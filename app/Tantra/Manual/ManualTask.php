<?php

declare(strict_types=1);

namespace App\Tantra\Manual;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tantra: a post the owner publishes by hand (PRD 12): an X post or thread, or a
 * poll on a personal LinkedIn profile. waiting (no time yet) > due > posted,
 * skipped or expired (48 hours after it fell due).
 */
class ManualTask extends Model
{
    use HasUuids;

    public const EXPIRES_AFTER_HOURS = 48;

    protected $table = 'tantra_manual_tasks';

    protected $fillable = [
        'review_item_id', 'workspace_id', 'owner_user_id', 'kind', 'status', 'due_at', 'text', 'thread', 'poll',
        'media_url', 'posted_url', 'posted_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'posted_at' => 'datetime',
            'notified_at' => 'datetime',
            'thread' => 'array',
            'poll' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class, 'review_item_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['waiting', 'due'], true);
    }
}
