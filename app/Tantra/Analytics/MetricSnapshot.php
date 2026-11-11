<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Models\Post;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** tantra: one reading of a published post's numbers (PRD 10.6). Null means the source does not give it. */
class MetricSnapshot extends Model
{
    use HasUuids;

    protected $table = 'tantra_metric_snapshots';

    protected $fillable = [
        'post_id', 'review_item_id', 'source', 'captured_at', 'impressions', 'unique_impressions',
        'reactions', 'comments', 'shares', 'clicks', 'saves', 'engagement_rate', 'raw',
    ];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'engagement_rate' => 'float', 'raw' => 'array'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class, 'review_item_id');
    }
}
