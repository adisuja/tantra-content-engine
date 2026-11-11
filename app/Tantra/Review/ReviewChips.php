<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Models\Post;
use App\Tantra\Review\Models\ReviewItem;

/**
 * tantra: the review chip on TryPost's post cards (PRD 10.3): who approved the
 * post and whether it moved from its planned slot. Scoped to one request; a
 * page of cards is loaded in one query by prime().
 */
final class ReviewChips
{
    /** @var array<string, array{status: string, approved_by: string|null, slot_fallback: bool, batch_id: string, engine_post_id: string}|null> */
    private array $byGroup = [];

    /** @param iterable<int, Post> $posts */
    public function prime(iterable $posts): void
    {
        $groups = collect($posts)->pluck('post_group_id')->filter()->unique()->reject(fn (string $g): bool => array_key_exists($g, $this->byGroup))->values();
        if ($groups->isEmpty()) {
            return;
        }

        foreach ($groups as $group) {
            $this->byGroup[$group] = null;
        }

        ReviewItem::query()->whereIn('post_group_id', $groups)->with('approver:id,name')->get()
            ->each(function (ReviewItem $item): void {
                $this->byGroup[(string) $item->post_group_id] = [
                    'status' => $item->review_status->value,
                    'approved_by' => $item->approver?->name,
                    'slot_fallback' => (bool) $item->slot_fallback,
                    'batch_id' => $item->batch_id,
                    'engine_post_id' => $item->engine_post_id,
                ];
            });
    }

    /** @return array{status: string, approved_by: string|null, slot_fallback: bool, batch_id: string, engine_post_id: string}|null */
    public function for(Post $post): ?array
    {
        if ($post->post_group_id === null) {
            return null;
        }

        $this->prime([$post]);

        return $this->byGroup[$post->post_group_id] ?? null;
    }
}
