<?php

declare(strict_types=1);

namespace App\Tantra\Manual;

use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * tantra: the life of a manual task (PRD 12). Created when an assisted-manual
 * item is approved; due two hours after its LinkedIn parent goes out (X) or at
 * its own slot (personal polls); closed when the owner marks it posted.
 */
final class ManualTasks
{
    public const X_DELAY_HOURS = 2;

    public static function createFor(ReviewItem $item): ManualTask
    {
        $payload = $item->payload ?? [];
        $isPoll = data_get($item->media_manifest, 'type') === 'poll';
        $thread = array_values(array_filter((array) ($payload['thread'] ?? []), 'is_string'));
        $preview = data_get($item->media_manifest, 'previews.0');

        $kind = $item->platform === 'x' ? ($thread !== [] ? 'x_thread' : 'x_post') : 'linkedin_poll';
        // A poll holds its LinkedIn slot; X tasks hold none.
        $channel = $kind === 'linkedin_poll' && $item->workspace ? ImportBatch::channelFor($item->workspace, 'linkedin') : null;

        $task = ManualTask::query()->updateOrCreate(['review_item_id' => $item->id], [
            'workspace_id' => $item->workspace_id,
            'social_account_id' => $channel?->id,
            'slot_fallback' => false,
            'owner_user_id' => ReviewGate::reviewOwnerId($item),
            'kind' => $kind,
            'status' => 'waiting',
            'due_at' => null,
            'text' => (string) ($payload['text'] ?? ''),
            'thread' => $thread ?: null,
            'poll' => $isPoll ? data_get($item->media_manifest, 'poll') : null,
            'media_url' => $preview ? Storage::url((string) $preview) : null,
            'posted_url' => null,
            'posted_at' => null,
            'notified_at' => null,
        ]);

        self::schedule($task);

        return $task->refresh();
    }

    /**
     * The calendar's "Post by hand" strip: tasks timed inside the visible range.
     *
     * @return list<array{id: string, kind: string, status: string, at: string, text: string, fallback: bool}>
     */
    public static function forCalendar(string $workspaceId, ?string $channelId, CarbonInterface $from, CarbonInterface $until): array
    {
        return ManualTask::query()
            ->where('workspace_id', $workspaceId)
            ->when($channelId, fn ($q) => $q->where('social_account_id', $channelId))
            ->whereIn('status', ['waiting', 'due', 'posted'])
            ->whereBetween('due_at', [$from->toImmutable()->utc(), $until->toImmutable()->utc()])
            ->orderBy('due_at')
            ->limit(50)
            ->get()
            ->map(fn (ManualTask $t): array => [
                'id' => $t->id,
                'kind' => $t->kind,
                'status' => $t->status,
                'at' => $t->due_at->toIso8601ZuluString(),
                'text' => Str::limit(trim(strtok($t->text, "\n") ?: $t->text), 70),
                'fallback' => (bool) $t->slot_fallback,
            ])->values()->all();
    }

    /** Give a waiting task its time once it can be known. */
    public static function schedule(ManualTask $task): void
    {
        if ($task->status !== 'waiting' || $task->due_at !== null) {
            return;
        }

        $item = $task->item;
        $due = null;

        $fallback = false;
        if ($task->kind === 'linkedin_poll' && $task->social_account_id !== null) {
            // The same rule as a post: the planned slot if free and ahead, else the next free one.
            $channel = SocialAccount::query()->find($task->social_account_id);
            try {
                [$due, $fallback] = $channel ? ReviewActions::slotFor($item, $channel) : [null, false];
            } catch (ValidationException) {
                $due = null; // no free slot yet: try again on the next pass
            }
        } elseif ($task->kind === 'linkedin_poll') {
            if ($item->planned_date !== null && filled($item->planned_time)) {
                $zone = $item->timezone ?? WorkspaceSettings::query()->find($item->workspace_id)?->timezone ?? 'UTC';
                $due = CarbonImmutable::parse($item->planned_date->toDateString().' '.$item->planned_time, $zone)->utc();
            }
        } else {
            $parent = $item->parent_review_item_id ? ReviewItem::query()->find($item->parent_review_item_id) : null;
            $parentAt = $parent?->posts()->pluck('scheduled_at')->filter()->sort()->first();
            $due = $parentAt ? CarbonImmutable::parse($parentAt)->addHours(self::X_DELAY_HOURS) : null;
        }

        if ($due !== null) {
            $task->forceFill(['due_at' => $due, 'slot_fallback' => $fallback])->save();
        }
    }

    /**
     * The scheduler's pass: time the waiting tasks, mark the ones whose time has
     * come as due and tell their owner, expire the ones left for 48 hours.
     *
     * @return array{due: int, expired: int}
     */
    public static function tick(): array
    {
        ManualTask::query()->where('status', 'waiting')->whereNull('due_at')->with('item')->get()->each(fn (ManualTask $t) => self::schedule($t));

        $due = 0;
        ManualTask::query()->where('status', 'waiting')->where('due_at', '<=', now())->with(['item', 'owner'])->get()
            ->each(function (ManualTask $task) use (&$due): void {
                $task->forceFill(['status' => 'due', 'notified_at' => now()])->save();
                if ($task->owner !== null) {
                    Mail::to($task->owner)->queue(new ManualTaskDue($task->id, $task->kind, (string) $task->item?->engine_post_id, $task->text));
                }
                $due++;
            });

        $expired = ManualTask::query()->where('status', 'due')->where('due_at', '<=', now()->subHours(ManualTask::EXPIRES_AFTER_HOURS))
            ->update(['status' => 'expired', 'updated_at' => now()]);

        return ['due' => $due, 'expired' => $expired];
    }

    public static function markPosted(User $user, ManualTask $task, ?string $url): void
    {
        self::assertOwner($user, $task);

        if ($url !== null && $url !== '' && ! preg_match('#^https://(www\.)?(x\.com|twitter\.com|linkedin\.com)/#i', $url)) {
            throw ValidationException::withMessages(['url' => 'Paste the link of the post on X or LinkedIn.']);
        }

        DB::transaction(function () use ($user, $task, $url): void {
            $task->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_url' => $url ?: null])->save();
            $item = $task->item;
            $item->review_status = ReviewStatus::Published;
            $item->save();
            $item->record(EventKind::Comment, $user, 'Posted by hand.', array_filter(['posted_url' => $url]));
        });
    }

    public static function skip(User $user, ManualTask $task): void
    {
        self::assertOwner($user, $task);
        $task->forceFill(['status' => 'skipped'])->save();
        $task->item->record(EventKind::Comment, $user, 'Skipped: not posted by hand.');
    }

    private static function assertOwner(User $user, ManualTask $task): void
    {
        if (! $task->isOpen()) {
            throw ValidationException::withMessages(['task' => 'This task is already '.$task->status.'.']);
        }
        if (! ReviewGate::isReviewOwner($user, $task->item)) {
            throw ValidationException::withMessages(['task' => 'Only the owner of this post can mark it.']);
        }
    }
}
