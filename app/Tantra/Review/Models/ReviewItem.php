<?php

declare(strict_types=1);

namespace App\Tantra\Review\Models;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One engine post under review. It can span several channel posts (one
 * TryPost post per channel, grouped by post_group_id).
 *
 * @property string $id
 * @property string $account_id
 * @property string $batch_id
 * @property string $workspace_id
 * @property string $engine_batch_id
 * @property string $engine_post_id
 * @property string|null $parent_review_item_id
 * @property string $platform
 * @property string|null $post_group_id
 * @property bool $assisted_manual
 * @property ReviewStatus $review_status
 * @property int $revision
 * @property array<int, array{text: string, signer: string}>|null $numbers_for_signoff
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $media_manifest
 */
class ReviewItem extends Model
{
    use HasUuids;

    protected $table = 'tantra_review_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assisted_manual' => 'boolean',
            'slot_fallback' => 'boolean',
            'planned_date' => 'date:Y-m-d',
            'cta_date' => 'date:Y-m-d',
            'numbers_for_signoff' => 'array',
            'sources' => 'array',
            'flags' => 'array',
            'media_manifest' => 'array',
            'payload' => 'array',
            'review_status' => ReviewStatus::class,
            'approved_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_review_item_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReviewEvent::class)->orderBy('created_at');
    }

    /** @return Collection<int, Post> */
    public function posts(): Collection
    {
        return $this->post_group_id === null
            ? new Collection
            : Post::query()->where('post_group_id', $this->post_group_id)->get();
    }

    /** @param array<string, mixed>|null $value */
    public function record(EventKind $kind, ?User $user = null, ?string $body = null, ?array $value = null): ReviewEvent
    {
        return $this->events()->create([
            'user_id' => $user?->id,
            'kind' => $kind->value,
            'body' => $body,
            'value' => $value,
        ]);
    }
}
