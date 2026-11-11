<?php

declare(strict_types=1);

namespace App\Tantra\Review\Actions;

use App\Actions\Post\Queue\ListFreeQueueSlots;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * tantra: the review actions (PRD 10.2 and 10.3). Every action writes an event;
 * review_status is the cached projection of those events.
 */
final class ReviewActions
{
    public const UNAPPROVE_CUTOFF_MINUTES = 10;

    /**
     * Approve and schedule: the engine's planned slot when it is free and in the
     * future, otherwise the channel's next free queue slot. Assisted-manual items
     * (X, personal-profile polls) become `approved` and wait for the manual step.
     *
     * @return array{status: string, scheduled_at: string|null, slot_fallback: bool, message: string}
     */
    public static function approve(User $user, ReviewItem $item, ?string $note = null): array
    {
        ReviewGate::assertReviewOwner($user, $item);
        self::assertStatus($item, [ReviewStatus::InReview, ReviewStatus::ChangesRequested]);

        $open = collect($item->numbers_for_signoff ?? [])->first(fn (array $n): bool => blank($n['signed_at'] ?? null));
        if ($open !== null) {
            $who = $open['signer'] === 'adi' ? 'Adi' : 'the review owner';
            throw ValidationException::withMessages(['review' => "Sign off this number first ({$who}): {$open['text']}"]);
        }

        if ($item->assisted_manual || $item->post_group_id === null) {
            if (! $item->assisted_manual) {
                throw ValidationException::withMessages(['review' => 'No channel is connected for this post yet, so it cannot be scheduled.']);
            }
            self::settle($item, $user, ReviewStatus::Approved, EventKind::Approve, $note, ['manual' => true]);

            return ['status' => ReviewStatus::Approved->value, 'scheduled_at' => null, 'slot_fallback' => false, 'message' => 'Approved. It is posted by hand at its time.'];
        }

        $posts = $item->posts();
        $channelIds = $posts->pluck('social_account_id')->filter()->unique()->values()->all();

        return ReflowChannelQueue::withLock($channelIds, function () use ($user, $item, $posts, $note): array {
            $first = $posts->first();
            /** @var SocialAccount $channel */
            $channel = SocialAccount::query()->findOrFail($first->social_account_id);
            [$at, $fallback] = self::slotFor($item, $channel);

            if ($item->cta_date !== null && CarbonImmutable::parse($at)->setTimezone(self::zone($item, $channel))->toDateString() >= $item->cta_date->toDateString()) {
                $message = $fallback
                    ? 'The fallback slot is on or after the event. Pick a date or swap to the fallback CTA.'
                    : 'The planned slot is on or after the event. Pick a date or swap to the fallback CTA.';
                $item->record(EventKind::Comment, null, $message, ['blocked' => 'cta_date']);
                throw ValidationException::withMessages(['review' => $message]);
            }

            foreach ($posts as $post) {
                UpdatePost::execute($item->workspace, $post, [
                    'status' => PostStatus::Scheduled->value,
                    'scheduled_at' => $at->toIso8601String(),
                ], $user);
            }

            $item->slot_fallback = $fallback;
            self::settle($item, $user, ReviewStatus::Scheduled, EventKind::Approve, $note, [
                'scheduled_at' => $at->toIso8601String(),
                'slot_fallback' => $fallback,
                'comments_approved' => [],
            ]);

            $local = $at->setTimezone(self::zone($item, $channel));
            $message = $fallback
                ? 'Moved to '.$local->format('D j M H:i').', the planned slot was taken or past.'
                : 'Scheduled for '.$local->format('D j M H:i').'.';

            return ['status' => ReviewStatus::Scheduled->value, 'scheduled_at' => $at->toIso8601String(), 'slot_fallback' => $fallback, 'message' => $message];
        });
    }

    public static function unapprove(User $user, ReviewItem $item): void
    {
        ReviewGate::assertReviewOwner($user, $item);
        self::assertStatus($item, [ReviewStatus::Scheduled, ReviewStatus::Approved]);

        foreach ($item->posts() as $post) {
            if ($post->status === PostStatus::Scheduled && $post->scheduled_at?->lte(now()->addMinutes(self::UNAPPROVE_CUTOFF_MINUTES))) {
                throw ValidationException::withMessages(['review' => 'It goes out in under '.self::UNAPPROVE_CUTOFF_MINUTES.' minutes, too late to unapprove.']);
            }
        }

        foreach ($item->posts() as $post) {
            if ($post->status === PostStatus::Scheduled) {
                UpdatePost::execute($item->workspace, $post, ['status' => PostStatus::Draft->value, 'scheduled_at' => null], $user);
            }
        }

        $item->slot_fallback = false;
        $item->approved_by = null;
        $item->approved_at = null;
        self::settle($item, $user, ReviewStatus::InReview, EventKind::Unapprove);
    }

    public static function requestChanges(User $user, ReviewItem $item, string $note): void
    {
        ReviewGate::assertReviewOwner($user, $item);
        self::assertStatus($item, [ReviewStatus::InReview, ReviewStatus::ChangesRequested]);

        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say what to change.']);
        }

        self::settle($item, $user, ReviewStatus::ChangesRequested, EventKind::RequestChanges, $note);
    }

    public static function reject(User $user, ReviewItem $item, ?string $note = null): void
    {
        ReviewGate::assertReviewOwner($user, $item);

        if (in_array($item->review_status, [ReviewStatus::Scheduled, ReviewStatus::Published], true)) {
            throw ValidationException::withMessages(['review' => 'Unapprove it first.']);
        }

        self::settle($item, $user, ReviewStatus::Rejected, EventKind::Reject, $note);
    }

    public static function comment(User $user, ReviewItem $item, string $body): void
    {
        if (! ReviewGate::canView($user, $item)) {
            throw ValidationException::withMessages(['review' => 'You cannot comment here.']);
        }
        if (blank($body)) {
            throw ValidationException::withMessages(['body' => 'Write a comment.']);
        }

        $item->record(EventKind::Comment, $user, $body);
    }

    public static function signOff(User $user, ReviewItem $item, int $index): void
    {
        $numbers = $item->numbers_for_signoff ?? [];
        $entry = $numbers[$index] ?? null;

        if ($entry === null) {
            throw ValidationException::withMessages(['index' => 'No such number.']);
        }
        if (! ReviewGate::canSign($user, $item, (string) $entry['signer'])) {
            throw ValidationException::withMessages(['index' => $entry['signer'] === 'adi' ? 'Only Adi can sign off this number.' : 'Only the review owner can sign off this number.']);
        }

        $numbers[$index] = [...$entry, 'signed_by' => $user->id, 'signed_at' => now()->toIso8601String()];
        DB::transaction(function () use ($item, $numbers, $user, $index, $entry): void {
            $item->numbers_for_signoff = $numbers;
            $item->save();
            $item->record(EventKind::SignoffNumber, $user, $entry['text'], ['index' => $index]);
        });
    }

    /**
     * The planned instant if it is in the future and free, else the next free slot.
     *
     * @return array{0: CarbonImmutable, 1: bool}
     */
    private static function slotFor(ReviewItem $item, SocialAccount $channel): array
    {
        $planned = null;
        if ($item->planned_date !== null && filled($item->planned_time)) {
            $planned = CarbonImmutable::parse($item->planned_date->toDateString().' '.$item->planned_time, self::zone($item, $channel))->utc();
        }

        if ($planned !== null && $planned->greaterThan(now()->addMinute())) {
            $taken = Post::query()->occupyingSlotsOn($channel->id, now())->where('scheduled_at', $planned)->exists();
            if (! $taken) {
                return [$planned, false];
            }
        }

        $free = ListFreeQueueSlots::handle($channel)[0] ?? null;
        if ($free === null) {
            throw ValidationException::withMessages(['review' => 'The planned slot is taken or past and this channel has no free posting slot. Set its posting schedule, then approve again.']);
        }

        return [$free, true];
    }

    private static function zone(ReviewItem $item, SocialAccount $channel): string
    {
        return $item->timezone
            ?? WorkspaceSettings::query()->find($item->workspace_id)?->timezone
            ?? $channel->timezone
            ?? 'UTC';
    }

    /** @param list<ReviewStatus> $allowed */
    private static function assertStatus(ReviewItem $item, array $allowed): void
    {
        if (! in_array($item->review_status, $allowed, true)) {
            throw ValidationException::withMessages(['review' => 'This post is '.str_replace('_', ' ', $item->review_status->value).'.']);
        }
    }

    /** @param array<string, mixed>|null $value */
    private static function settle(ReviewItem $item, User $user, ReviewStatus $status, EventKind $kind, ?string $note = null, ?array $value = null): void
    {
        DB::transaction(function () use ($item, $user, $status, $kind, $note, $value): void {
            $item->review_status = $status;
            if ($kind === EventKind::Approve) {
                $item->approved_by = $user->id;
                $item->approved_at = now();
                $item->approval_note = $note;
            }
            $item->save();
            $item->record($kind, $user, $note, $value);
        });
    }
}
