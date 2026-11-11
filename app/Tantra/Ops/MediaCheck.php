<?php

declare(strict_types=1);

namespace App\Tantra\Ops;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * tantra: the media-exists check (PRD 8.5), next to TryPost's own connection
 * check for posts due soon. A scheduled post in a Tantra workspace whose file
 * is missing from storage would fail at publish time; its review owner hears
 * about it while there is still time to fix it, once per post.
 */
final class MediaCheck
{
    public const AHEAD_HOURS = 24;

    /** @return int posts with a missing file */
    public static function run(): int
    {
        $missing = 0;
        $posts = Post::query()
            ->where('status', PostStatus::Scheduled)
            ->whereBetween('scheduled_at', [now(), now()->addHours(self::AHEAD_HOURS)])
            ->whereIn('workspace_id', WorkspaceSettings::query()->select('workspace_id'))
            ->with('workspace.account')
            ->get();

        foreach ($posts as $post) {
            $gone = collect($post->media ?? [])->pluck('path')->filter()
                ->reject(fn (string $path): bool => self::exists($path))->values();
            if ($gone->isEmpty()) {
                continue;
            }

            $missing++;
            if (! Cache::add("tantra:media-missing:{$post->id}", true, now()->addDays(2))) {
                continue;
            }

            $ownerId = ReviewGate::reviewOwnerIdFor($post->workspace_id, $post->workspace?->account?->owner_id);
            $owner = $ownerId ? User::query()->find($ownerId) : null;
            if ($owner !== null) {
                Mail::to($owner)->queue(new MediaMissing($post->id, $post->workspace?->name ?? '', $post->scheduled_at->toIso8601ZuluString(), mb_substr(trim((string) $post->content), 0, 90)));
            }
        }

        return $missing;
    }

    private static function exists(string $path): bool
    {
        try {
            return Storage::exists($path);
        } catch (Throwable) {
            return true; // storage unreachable is the health check's job, not a missing file
        }
    }
}
