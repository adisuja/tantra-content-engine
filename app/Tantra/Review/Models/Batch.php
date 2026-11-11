<?php

declare(strict_types=1);

namespace App\Tantra\Review\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One engine batch as it lands in one workspace (an engine batch can hold posts
 * for several people, so it becomes one row per workspace).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $engine_batch_id
 * @property string|null $title
 * @property string|null $dates_label
 * @property string $status
 */
class Batch extends Model
{
    use HasUuids;

    protected $table = 'tantra_batches';

    protected $fillable = ['workspace_id', 'engine_batch_id', 'title', 'dates_label', 'imported_by', 'imported_at', 'source_commit', 'status'];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReviewItem::class, 'batch_id');
    }
}
