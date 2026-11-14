<?php

declare(strict_types=1);

namespace App\Tantra\Review\Actions;

use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Facades\DB;

/**
 * tantra: posts imported for a workspace with no channel yet (a teammate who has
 * not joined) keep their media held, and get their TryPost drafts the moment a
 * channel exists: when the teammate connects LinkedIn, or when the post is
 * approved (PRD 10.9, team onboarding).
 */
final class DraftMaker
{
    public const NO_CHANNEL_FLAG = 'No LinkedIn channel is connected in this workspace yet. Connect it under Channels and the post is ready to schedule.';

    /** Held uploads are left out of upstream's temporary-upload pruning. */
    public const HELD_COLLECTION = 'tantra_held';

    /** @param list<string> $tokens */
    public static function hold(Workspace $workspace, ReviewItem $item, array $tokens): void
    {
        $tokens = array_values(array_filter($tokens, 'is_string'));
        if ($tokens !== []) {
            Media::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('upload_token', $tokens)
                ->update(['collection' => self::HELD_COLLECTION]);
        }

        $item->media_manifest = [...($item->media_manifest ?? []), 'held_tokens' => $tokens];
    }

    /** Make the drafts for an item that has none yet. Returns true when it now has them. */
    public static function forItem(ReviewItem $item, ?User $actor = null): bool
    {
        if ($item->post_group_id !== null) {
            return true;
        }
        if ($item->assisted_manual) {
            return false;
        }

        $workspace = $item->workspace;
        $channel = $workspace ? ImportBatch::channelForPost($workspace, [...($item->payload ?? []), 'platform' => $item->platform]) : null;
        if ($channel === null) {
            return false;
        }

        $creator = $actor ?? User::query()->find($workspace->account?->owner_id);
        if ($creator === null) {
            return false;
        }

        return DB::transaction(function () use ($item, $workspace, $channel, $creator): bool {
            $item = ReviewItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->post_group_id !== null) {
                return true;
            }

            $tokens = array_values((array) data_get($item->media_manifest, 'held_tokens', []));
            if ($tokens !== []) {
                Media::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('upload_token', $tokens)
                    ->update(['collection' => Media::COLLECTION_UPLOADS]);
            }

            $post = [...($item->payload ?? []), 'upload_tokens' => $tokens, 'platform' => $item->platform];
            $item->post_group_id = ImportBatch::createDrafts($creator, $workspace, $channel, $item->batch, $post);
            $item->flags = array_values(array_filter($item->flags ?? [], fn ($f): bool => $f !== self::NO_CHANNEL_FLAG));
            $item->media_manifest = [...($item->media_manifest ?? []), 'held_tokens' => []];
            $item->save();
            $item->record(EventKind::Revised, $creator, 'Drafts created now that a channel is connected.', ['revision' => $item->revision, 'source' => 'channel_connected']);

            return true;
        });
    }

    /** Every waiting item of a workspace, after a channel was connected there. */
    public static function forWorkspace(SocialAccount $channel): int
    {
        $made = 0;
        ReviewItem::query()
            ->where('workspace_id', $channel->workspace_id)
            ->whereNull('post_group_id')
            ->where('assisted_manual', false)
            ->whereIn('review_status', ['in_review', 'changes_requested'])
            ->get()
            ->each(function (ReviewItem $item) use (&$made): void {
                try {
                    $made += self::forItem($item) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $made;
    }
}
