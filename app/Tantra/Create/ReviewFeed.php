<?php

declare(strict_types=1);

namespace App\Tantra\Create;

use App\Enums\Post\Status as PostStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use App\Tantra\LinkedIn\Identity;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * tantra: Content > Review in Tantra (AF-09, PRD 9.22): every post already created, as the phone preview shows it, with
 * what the person may do to each (approve, edit the text, reject). A post someone else approves comes too, read only,
 * with who approves it (`approver`, `waiting_on_other`), so nothing waiting looks missing. The actions are the review studio's own
 * (ReviewActions), so the approval rules and the slots stay the engine's.
 */
final class ReviewFeed
{
    public const STATES = [
        'waiting' => [ReviewStatus::InReview, ReviewStatus::ChangesRequested],
        'approved' => [ReviewStatus::Approved, ReviewStatus::Scheduled],
        'published' => [ReviewStatus::Published, ReviewStatus::Failed],
        'rejected' => [ReviewStatus::Rejected],
    ];

    public const TEXT_MAX = 10000;

    /**
     * counts.waiting_others: of the waiting posts, those someone else approves.
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function for(User $user, string $state = 'waiting', int $limit = 60): array
    {
        $state = array_key_exists($state, self::STATES) ? $state : 'waiting';
        $visible = ReviewPageController::visibleWorkspaceIds($user)->all();
        $base = fn () => ReviewItem::query()->where('account_id', $user->account_id)->whereIn('workspace_id', $visible);
        $values = fn (string $s): array => array_map(fn (ReviewStatus $r): string => $r->value, self::STATES[$s]);

        $items = $base()->whereIn('review_status', $values($state))->with('workspace')->latest('updated_at')->limit(max(1, min($limit, 200)))->get();
        $posts = Post::query()->whereIn('post_group_id', $items->pluck('post_group_id')->filter())->get()->groupBy('post_group_id');

        $approvers = [];
        $counts = collect(array_keys(self::STATES))->mapWithKeys(fn (string $s): array => [$s => $base()->whereIn('review_status', $values($s))->count()])->all();
        $others = ReviewGate::othersWorkspaceIds($user, $visible);
        $counts['waiting_others'] = $others === [] ? 0 : $base()->whereIn('workspace_id', $others)->whereIn('review_status', $values('waiting'))->count();

        return [
            'items' => $items->map(function (ReviewItem $i) use ($user, $posts, &$approvers): array {
                return self::item($i, $user, $posts->get($i->post_group_id, new Collection), $approvers);
            })->values()->all(),
            'counts' => $counts,
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  array<string, ?string>  $approvers  memo for ReviewGate::reviewOwnerName
     * @return array<string, mixed>
     */
    public static function item(ReviewItem $i, User $user, Collection $posts, array &$approvers = []): array
    {
        $p = (array) ($i->payload ?? []);
        $manifest = (array) ($i->media_manifest ?? []);
        $media = collect($posts->first()?->media ?? []);
        $held = array_values(array_filter((array) ($manifest['held_tokens'] ?? []), 'is_string'));
        if ($media->isEmpty() && $held !== []) {
            $media = Media::query()->where('workspace_id', $i->workspace_id)->whereIn('upload_token', $held)->orderBy('order')->get()
                ->map(fn (Media $m): array => ['url' => $m->url, 'mime_type' => $m->mime_type]);
        }
        $images = $media->filter(fn ($m): bool => str_starts_with((string) ($m['mime_type'] ?? ''), 'image/'))->pluck('url')->filter()->values();
        if ($images->isEmpty()) {
            $images = collect($manifest['previews'] ?? [])->filter(fn ($path): bool => is_string($path))->map(fn (string $path): string => Storage::url($path))->values();
        }
        $video = $media->first(fn ($m): bool => str_starts_with((string) ($m['mime_type'] ?? ''), 'video/'));
        $network = $i->platform === 'x' ? 'x' : 'linkedin';
        $who = Identity::forWorkspace((string) $i->workspace_id, $network) ?? [];
        $owner = ReviewGate::isReviewOwner($user, $i);
        $open = in_array($i->review_status, self::STATES['waiting'], true);

        return [
            'ref' => $i->id,
            'platform' => $i->platform,
            'status' => $i->review_status->value,
            'title' => $i->short,
            'format' => $i->format,
            'text' => (string) ($p['text'] ?? $posts->first()?->content ?? ''),
            'first_comment' => $p['first_comment'] ?? null,
            'images' => $images->take(10)->values()->all(),
            'video' => $video['url'] ?? null,
            'author' => [
                'name' => (string) (($who['name'] ?? '') ?: ($i->workspace?->name ?? '')),
                'headline' => (string) ($who['headline'] ?? ''),
                'avatar' => $who['avatar'] ?? null,
            ],
            'workspace' => $i->workspace?->name,
            'planned' => ['date' => $i->planned_date?->toDateString(), 'time' => $i->planned_time, 'tz' => $i->timezone],
            'scheduled_at' => $posts->pluck('scheduled_at')->filter()->sort()->first()?->toIso8601String(),
            'post_url' => $posts->pluck('platform_url')->filter()->first(),
            'updated_at' => $i->updated_at?->toIso8601String(),
            'approver' => ReviewGate::reviewOwnerName($i, $approvers),
            'waiting_on_other' => $open && ! $owner,
            'can_approve' => $owner && $open,
            'can_edit' => $owner && $open,
            'can_reject' => $owner && ! in_array($i->review_status, [ReviewStatus::Scheduled, ReviewStatus::Published, ReviewStatus::Rejected], true),
        ];
    }

    /** Change the post's text while it waits for review: the review item and its channel drafts. */
    public static function editText(User $user, ReviewItem $item, string $text): void
    {
        ReviewGate::assertReviewOwner($user, $item);
        if (! in_array($item->review_status, self::STATES['waiting'], true)) {
            throw ValidationException::withMessages(['review' => 'Only a post waiting for review can be edited.']);
        }
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            throw ValidationException::withMessages(['text' => 'The post needs some text.']);
        }
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw ValidationException::withMessages(['text' => 'A post can be 10,000 characters at most.']);
        }
        if (preg_match('/[\x{2013}\x{2014}]/u', $text) === 1) {
            throw ValidationException::withMessages(['text' => 'No en or em dashes, please.']);
        }

        DB::transaction(function () use ($user, $item, $text): void {
            $item->payload = [...(array) ($item->payload ?? []), 'text' => $text];
            $item->save();
            if (filled($item->post_group_id)) {
                Post::query()->where('post_group_id', $item->post_group_id)->where('status', PostStatus::Draft->value)->update(['content' => $text]);
            }
            $item->record(EventKind::Revised, $user, 'Text edited in Tantra.');
        });
    }
}
