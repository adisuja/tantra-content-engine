<?php

declare(strict_types=1);

namespace App\Tantra\Review\Models;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Events\ReviewItemChanged;
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
 * @property string|null $idea_id the Tantra idea it was sent from (PRD 25.8)
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
            'thread_overrides' => 'array',
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

    /**
     * The team thread as it will be posted: the engine's entries with the review
     * owner's edits, each with its index, edited flag and excluded flag. An entry
     * whose comment was removed is excluded too.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(): array
    {
        $overrides = (array) ($this->thread_overrides ?? []);
        // Indexes are the positions in the pushed list, the same ones reply_to points at.
        $out = [];
        foreach (array_values((array) data_get($this->payload, 'engagement_comments', [])) as $index => $entry) {
            if (! is_array($entry) || blank($entry['text'] ?? null)) {
                continue;
            }
            $o = (array) ($overrides[(string) $index] ?? []);
            $parent = $entry['reply_to'] ?? null;
            $out[] = [
                ...$entry,
                'index' => $index,
                'text' => (string) ($o['text'] ?? $entry['text']),
                'edited' => isset($o['text']) && $o['text'] !== $entry['text'],
                'excluded' => (bool) ($o['excluded'] ?? false) || ($parent !== null && (bool) data_get($overrides, $parent.'.excluded', false)),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $value */
    public function record(EventKind $kind, ?User $user = null, ?string $body = null, ?array $value = null): ReviewEvent
    {
        $event = $this->events()->create([
            'user_id' => $user?->id,
            'kind' => $kind->value,
            'body' => $body,
            'value' => $value,
        ]);

        ReviewItemChanged::dispatch($this->batch_id, $this->id, $kind->value, $user?->id);

        return $event;
    }
}
