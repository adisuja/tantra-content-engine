<?php

declare(strict_types=1);

namespace App\Tantra\Review\Models;

use App\Models\User;
use App\Tantra\Review\Enums\EventKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: rows are never updated or deleted. The review state of an item
 * is a projection of its events (PRD section 9).
 */
class ReviewEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'tantra_review_events';

    protected $fillable = ['user_id', 'kind', 'body', 'value'];

    protected function casts(): array
    {
        return ['kind' => EventKind::class, 'value' => 'array', 'created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class, 'review_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
