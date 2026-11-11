<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Models\Post;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** tantra: one boost of one published post (PRD 10.5 step 4). */
class BoostRun extends Model
{
    use HasUuids;

    protected $table = 'tantra_boost_runs';

    protected $fillable = ['post_id', 'review_item_id', 'published_at', 'unipile_post_ref', 'window_start_at', 'window_seconds', 'status', 'skipped_reason'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'window_start_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class, 'review_item_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(BoostAction::class, 'run_id');
    }
}
