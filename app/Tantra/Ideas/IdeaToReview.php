<?php

declare(strict_types=1);

namespace App\Tantra\Ideas;

use App\Models\Idea;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Actions\DraftMaker;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * tantra: Send to review (PRD 25.8). The idea becomes a review item for its
 * workspace's review owner through the batch import (engine batch
 * "ideas-<date>"), with a draft on the idea's channel in that network's post
 * type, so approval places it in that channel's slots (10.3). X goes out by
 * hand (PRD 12): its item is assisted manual and its task is due at the
 * planned time. The idea and the item link to each other; sending twice
 * returns the same item.
 */
final class IdeaToReview
{
    /** Review items still holding a planned time on a channel. */
    private const OPEN = [ReviewStatus::InReview, ReviewStatus::ChangesRequested, ReviewStatus::Approved, ReviewStatus::Scheduled];

    /**
     * Why Send to review is not possible yet, or null when it is.
     */
    public static function blocker(IdeaMeta $meta, ?Workspace $workspace = null): ?string
    {
        $workspace ??= $meta->idea?->workspace;
        if ($workspace === null) {
            return 'This idea has no workspace.';
        }
        if ($meta->state === IdeaMeta::STATE_DROPPED) {
            return 'This idea was dropped. Set it back to idea first.';
        }
        if (blank(WorkspaceSettings::query()->find($workspace->id)?->brand_profile_key)) {
            return 'This workspace has no engine profile yet. Map one with tantra:map-profile first.';
        }

        try {
            self::channel($meta, $workspace);
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * @return array{outcome: string, review_item_id: string, review_url: string, idea: array<string, mixed>}
     */
    public static function execute(User $actor, Idea $idea): array
    {
        return DB::transaction(function () use ($actor, $idea): array {
            /** @var IdeaMeta $meta */
            $meta = IdeaMeta::query()->whereKey($idea->id)->lockForUpdate()->firstOrFail();

            $linked = $meta->review_item_id ? ReviewItem::query()->find($meta->review_item_id) : null;
            if ($linked !== null && $linked->review_status !== ReviewStatus::Superseded) {
                return self::result('unchanged', $linked, $meta);
            }

            $workspace = $idea->workspace;
            if (($reason = self::blocker($meta, $workspace)) !== null) {
                throw ValidationException::withMessages(['idea' => $reason]);
            }

            $settings = WorkspaceSettings::query()->findOrFail($workspace->id);
            $channel = self::channel($meta, $workspace);
            $preview = TantraIdeas::preview($meta);
            $raw = (array) ($meta->preview ?? []);
            [$date, $time, $tz] = self::plannedSlot($meta, $workspace, $settings, $channel);

            $tokens = array_values((array) ($meta->upload_tokens ?? []));
            if ($tokens !== []) {
                // Held since the upsert; the drafts take them now.
                Media::query()->where('workspace_id', $workspace->id)->whereIn('upload_token', $tokens)
                    ->where('collection', DraftMaker::HELD_COLLECTION)
                    ->update(['collection' => Media::COLLECTION_UPLOADS]);
            }

            $isPage = $meta->platform === 'linkedin_page';
            $post = array_filter([
                'engine_post_id' => self::engineId($meta),
                'profile' => (string) $settings->brand_profile_key,
                // The review screen and the slots know the page as a LinkedIn post by a company.
                'platform' => $isPage ? 'linkedin' : $meta->platform,
                'date' => $date,
                'time' => $time,
                'tz' => $tz,
                'text' => $preview['text'],
                'first_comment' => $preview['first_comment'],
                'thread' => $preview['thread'] ?: null,
                'format' => $meta->format,
                'short' => $idea->title,
                'idea' => $meta->hook ?? $idea->title,
                'sample' => $meta->template_key ? ['id' => $meta->template_key] : null,
                'media' => array_filter(['type' => $preview['media']['type'], 'title' => $preview['media']['title'] ?? $preview['title'], 'tall' => $preview['media']['tall'] ?: null]),
                'preview_paths' => array_values(array_filter((array) data_get($raw, 'media.files', []), fn ($f): bool => is_string($f) && str_starts_with($f, 'tantra/previews/'))) ?: null,
                'upload_tokens' => $tokens ?: null,
                'profile_card' => $isPage ? ['kind' => 'company'] : null,
                'social_account_id' => $channel?->id,
                'content_type' => $channel ? Networks::contentType($channel, $raw, $meta->format)->value : null,
                'idea_id' => $idea->id,
                'idea_kind' => $meta->kind,
            ], fn ($v): bool => $v !== null && $v !== []);

            $result = ImportBatch::execute($actor, [
                'engine_batch_id' => 'ideas-'.now()->format('Ymd'),
                'title' => 'Ideas sent to review '.now()->format('j M'),
                'posts' => [$post],
            ]);

            $item = ReviewItem::query()->findOrFail($result['items'][0]['review_item_id']);
            $item->forceFill(['idea_id' => $idea->id])->save();
            $meta->forceFill(['review_item_id' => $item->id, 'state' => IdeaMeta::STATE_IN_REVIEW])->save();

            return self::result((string) $result['items'][0]['outcome'], $item, $meta);
        });
    }

    /**
     * The idea's channel: the one it names, else the workspace's one channel
     * for that network. None for X, which is posted by hand.
     *
     * @throws ValidationException
     */
    public static function channel(IdeaMeta $meta, Workspace $workspace): ?SocialAccount
    {
        $label = Networks::label($meta->platform);
        $channels = Networks::channels($workspace, $meta->platform);

        if (Networks::byHand($meta->platform)) {
            return null;
        }
        if ($meta->channel_id !== null) {
            return $channels->firstWhere('id', $meta->channel_id)
                ?? throw ValidationException::withMessages(['channel_id' => "The chosen {$label} channel is not connected any more. Connect it or pick another."]);
        }

        return match ($channels->count()) {
            0 => throw ValidationException::withMessages(['idea' => "Connect {$label} to publish first."]),
            1 => $channels->first(),
            default => throw ValidationException::withMessages(['channel_id' => "This workspace has {$channels->count()} {$label} channels. Pick one with channel_id first."]),
        };
    }

    /**
     * The planned date (the target date, else tomorrow for X so its task gets a
     * time) and a time from the channel's posting times on that day, skipping
     * times other open items already hold there. A hint: approval takes the
     * real slot under the channel's queue lock.
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private static function plannedSlot(IdeaMeta $meta, Workspace $workspace, WorkspaceSettings $settings, ?SocialAccount $channel): array
    {
        $tz = $channel?->timezone ?: ($settings->timezone ?: 'UTC');
        $date = $meta->target_date?->toDateString()
            ?? (Networks::byHand($meta->platform) ? CarbonImmutable::now($tz)->addDay()->toDateString() : null);

        if ($date === null) {
            return [null, null, $tz];
        }

        $day = CarbonImmutable::parse($date, $tz)->dayOfWeek;
        $entry = $channel?->posting_schedule?->days()[$day] ?? null;
        $times = $entry !== null && $entry['enabled'] ? $entry['times'] : [];
        if ($times === []) {
            $times = array_values(array_filter([$settings->default_time]));
        }
        if ($times === []) {
            return [$date, null, $tz];
        }

        $platform = $meta->platform === 'linkedin_page' ? 'linkedin' : $meta->platform;
        $used = ReviewItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('platform', $platform)
            ->whereDate('planned_date', $date)
            ->whereIn('review_status', array_map(fn (ReviewStatus $s): string => $s->value, self::OPEN))
            ->pluck('planned_time')
            ->filter()
            ->all();

        $free = array_values(array_diff($times, $used));

        return [$date, $free[0] ?? $times[0], $tz];
    }

    /** The review item's post id: the external key when it fits, else a short hash of it. */
    private static function engineId(IdeaMeta $meta): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $meta->external_key) === 1
            ? $meta->external_key
            : 'idea-'.substr(hash('sha256', $meta->external_key), 0, 16);
    }

    /** @return array{outcome: string, review_item_id: string, review_url: string, idea: array<string, mixed>} */
    private static function result(string $outcome, ReviewItem $item, IdeaMeta $meta): array
    {
        return [
            'outcome' => $outcome,
            'review_item_id' => $item->id,
            'review_url' => url('/tantra/review/'.$item->batch_id.'#'.$item->engine_post_id),
            'idea' => TantraIdeas::present($meta->refresh()),
        ];
    }
}
