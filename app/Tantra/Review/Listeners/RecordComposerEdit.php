<?php

declare(strict_types=1);

namespace App\Tantra\Review\Listeners;

use App\Models\Post;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Facades\Auth;

/**
 * tantra: an edit in TryPost's composer to a post under review bumps the review
 * item's revision and records who changed it (PRD 10.2 "Edit").
 */
final class RecordComposerEdit
{
    public static function handle(Post $post): void
    {
        if ($post->post_group_id === null || ! $post->wasChanged(['content', 'media']) || Auth::user() === null) {
            return;
        }

        $item = ReviewItem::query()->where('post_group_id', $post->post_group_id)->first();
        if ($item === null || ! $item->review_status->isEditableByEngine()) {
            return;
        }

        $item->revision = $item->revision + 1;
        $item->save();
        $item->record(EventKind::Revised, Auth::user(), 'Edited in the composer.', ['revision' => $item->revision, 'source' => 'composer']);
    }
}
