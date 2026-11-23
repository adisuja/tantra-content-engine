<?php

declare(strict_types=1);

namespace App\Tantra\Kit;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\BoostRun;
use App\Tantra\Boost\ThreadDelivery;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * tantra: One Content app PRD 3.8, the post kit. The engine stays the source of truth for what goes out around a post, since it runs
 * the boost:
 *
 *   - first_comment: the publisher's first comment (payload.first_comment, and meta.thread_replies[0] on the channel posts)
 *   - author_reply:  the publisher's reply to that first comment (payload.author_reply), posted by the boost run from their account
 *   - entries:       the team boost comments and replies (payload.engagement_comments), each keyed by its member's Clerk user id
 *
 * After the run each entry carries its status and skip reason. The etag is a hash of the thread's content (never its run state), so
 * an edit based on an older read is refused (409). After approval the kit is read only (the CRM shows "Unapprove to edit").
 */
final class PostKit
{
    /** The thread key of an entry written for a member who has no brand profile: user:<engine user id>. */
    public const string USER_KEY = 'user:';

    public const int ENTRIES_MAX = 20;

    public const int TEXT_MAX = 3000;

    /** @return array<string, mixed> */
    public static function present(ReviewItem $item): array
    {
        $posts = $item->posts();
        $post = $posts->first(fn (Post $p): bool => BoostRun::query()->where('post_id', $p->id)->exists()) ?? $posts->first();
        $cache = [];
        $delivery = ThreadDelivery::for($item, $post, null, $cache);
        $states = collect($delivery['entries'])->keyBy('index');

        $thread = $item->thread();
        $keys = array_values(array_unique(array_map(fn (array $e): string => (string) ($e['by'] ?? ''), $thread)));
        $people = ThreadDelivery::people((string) $item->account_id, $keys, null, $cache);

        $entries = [];
        foreach ($thread as $e) {
            $state = $states->get((int) $e['index'], []);
            $user = $people[(string) ($e['by'] ?? '')]['user'] ?? null;
            $entries[] = [
                'index' => (int) $e['index'],
                'kind' => ($e['reply_to'] ?? null) === null ? 'comment' : 'reply',
                'reply_to' => isset($e['reply_to']) ? (int) $e['reply_to'] : null,
                'member' => $user?->clerk_user_id ?? ($e['member'] ?? null),
                'by' => (string) ($e['by'] ?? ''),
                'name' => $state['poster'] ?? $user?->name,
                'text' => (string) $e['text'],
                'excluded' => (bool) $e['excluded'],
                'edited' => (bool) $e['edited'],
                'status' => $state['state'] ?? 'ready',
                'skip_reason' => $state['reason'] ?? null,
                'posted_at' => $state['posted_at'] ?? null,
            ];
        }

        $run = $post ? BoostRun::query()->where('post_id', $post->id)->first() : null;
        $authorAction = $run ? BoostAction::query()->where('run_id', $run->id)->where('kind', 'author_reply')->first() : null;
        $authorReply = self::text(data_get($item->payload, 'author_reply'));

        return [
            'ref' => (string) $item->id,
            'review_status' => $item->review_status?->value,
            'read_only' => ! self::editable($item),
            'etag' => self::etag($item),
            'first_comment' => self::text(data_get($item->payload, 'first_comment')),
            'author_reply' => $authorReply === null ? null : [
                'text' => $authorReply,
                'status' => $authorAction?->status ?? 'ready',
                'skip_reason' => $authorAction !== null && $authorAction->status !== 'posted' ? $authorAction->error : null,
                'posted_at' => $authorAction?->posted_at?->toIso8601ZuluString(),
            ],
            'entries' => $entries,
            'run' => $run === null ? null : ['status' => $run->status, 'skipped_reason' => $run->skipped_reason, 'post_id' => (string) $run->post_id],
        ];
    }

    /** A hash of the thread's content: what a writer saw. Run states are left out, so a boost running never stales an edit. */
    public static function etag(ReviewItem $item): string
    {
        $thread = array_map(fn (array $e): array => [
            (int) $e['index'], (string) ($e['by'] ?? ''), (string) $e['text'], $e['reply_to'] ?? null, (bool) $e['excluded'],
        ], $item->thread());

        return sha1((string) json_encode([
            self::text(data_get($item->payload, 'first_comment')),
            self::text(data_get($item->payload, 'author_reply')),
            $thread,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The If-Match value without quotes or a weak prefix. */
    public static function cleanEtag(?string $header): string
    {
        $value = trim((string) $header);
        if (str_starts_with($value, 'W/')) {
            $value = substr($value, 2);
        }

        return trim($value, '" ');
    }

    public static function editable(ReviewItem $item): bool
    {
        return (bool) $item->review_status?->isWaiting();
    }

    /**
     * Replace the kit. Validation and the review owner rule apply; the caller has already checked the etag and that it is editable.
     *
     * @param  array{first_comment?: ?string, author_reply?: ?string, entries?: list<array<string, mixed>>}  $data
     * @param  array<string, bool>  $present  which of first_comment, author_reply and entries the request carried
     */
    public static function save(User $user, ReviewItem $item, array $data, array $present): void
    {
        ReviewGate::assertReviewOwnerUnlessCrm($user, $item);

        $payload = (array) ($item->payload ?? []);
        $overrides = (array) ($item->thread_overrides ?? []);

        foreach (['first_comment', 'author_reply'] as $field) {
            if ($present[$field] ?? false) {
                $text = self::text($data[$field] ?? null);
                self::assertCopy($text, $field);
                $payload[$field] = $text;
            }
        }

        if ($present['entries'] ?? false) {
            [$payload['engagement_comments'], $overrides] = self::entries($item, (array) ($data['entries'] ?? []));
        }

        DB::transaction(function () use ($user, $item, $payload, $overrides, $present): void {
            $item->payload = $payload;
            $item->thread_overrides = $overrides === [] ? null : $overrides;
            $item->save();
            if ($present['first_comment'] ?? false) {
                self::firstCommentOnPosts($item, $payload['first_comment'] ?? null);
            }
            $item->record(EventKind::Comment, $user, 'Post kit edited.', ['kit' => array_keys(array_filter($present))]);
        });

        KitEvents::threadUpdated($item->refresh(), $user);
    }

    /**
     * The new thread, member ids mapped to this engine's thread keys, and its excluded flags as overrides.
     *
     * @param  list<mixed>  $rows
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, bool>>}
     */
    private static function entries(ReviewItem $item, array $rows): array
    {
        if (count($rows) > self::ENTRIES_MAX) {
            throw ValidationException::withMessages(['entries' => 'A post kit has '.self::ENTRIES_MAX.' team comments at most.']);
        }

        $out = [];
        $overrides = [];
        $keys = [];
        foreach (array_values($rows) as $i => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["entries.{$i}" => 'Each entry is an object.']);
            }
            $text = self::text($row['text'] ?? null);
            if ($text === null) {
                throw ValidationException::withMessages(["entries.{$i}.text" => 'Write the comment, or remove it instead.']);
            }
            self::assertCopy($text, "entries.{$i}.text");

            $replyTo = $row['reply_to'] ?? null;
            if ($replyTo !== null) {
                if (! is_int($replyTo) || $replyTo < 0 || $replyTo >= $i || ($out[$replyTo]['reply_to'] ?? null) !== null) {
                    throw ValidationException::withMessages(["entries.{$i}.reply_to" => 'A reply points at an earlier team comment in this kit.']);
                }
            }

            $member = isset($row['member']) ? (string) $row['member'] : null;
            $by = $member !== null ? ($keys[$member] ??= self::keyFor($item, $member, $i)) : (string) ($row['by'] ?? '');
            if ($by === '') {
                throw ValidationException::withMessages(["entries.{$i}.member" => 'Name the member who posts this comment.']);
            }

            $out[] = array_filter(['by' => $by, 'member' => $member, 'text' => $text, 'reply_to' => $replyTo], fn (mixed $v): bool => $v !== null);
            if ((bool) ($row['excluded'] ?? false)) {
                $overrides[(string) $i] = ['excluded' => true];
            }
        }

        return [$out, $overrides];
    }

    /** The thread key for a member: their brand profile in this Account (this workspace first), else user:<engine user id>. */
    private static function keyFor(ReviewItem $item, string $member, int $i): string
    {
        $user = preg_match('/^user_[A-Za-z0-9]{10,64}$/', $member) === 1
            ? User::query()->where('clerk_user_id', $member)->where('account_id', $item->account_id)->first()
            : null;
        if ($user === null) {
            throw ValidationException::withMessages(["entries.{$i}.member" => 'That member has not signed in to the content engine yet.']);
        }

        $profile = WorkspaceSettings::query()->where('review_owner_user_id', $user->id)->whereNotNull('brand_profile_key')
            ->whereHas('workspace', fn ($q) => $q->where('account_id', $item->account_id))
            ->orderByRaw('case when workspace_id = ? then 0 else 1 end', [$item->workspace_id])
            ->value('brand_profile_key');

        return filled($profile) ? (string) $profile : self::USER_KEY.$user->id;
    }

    /** The channel posts not yet out carry the first comment as meta.thread_replies[0]. */
    private static function firstCommentOnPosts(ReviewItem $item, ?string $text): void
    {
        foreach ($item->posts() as $post) {
            if (in_array($post->status, [PostStatus::Published, PostStatus::Publishing], true)) {
                continue;
            }
            $meta = (array) ($post->meta ?? []);
            $replies = array_values((array) ($meta['thread_replies'] ?? []));
            if ($text === null) {
                // A thread (X, Threads) keeps its chain; only a lone first comment is removed.
                if (count($replies) > 1) {
                    continue;
                }
                unset($meta['thread_replies']);
            } else {
                $replies[0] = [...(array) ($replies[0] ?? []), 'text' => $text];
                $meta['thread_replies'] = $replies;
            }
            $post->forceFill(['meta' => $meta])->save();
        }
    }

    private static function assertCopy(?string $text, string $field): void
    {
        if ($text === null) {
            return;
        }
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw ValidationException::withMessages([$field => 'This can be '.self::TEXT_MAX.' characters at most.']);
        }
        if (preg_match('/[\x{2013}\x{2014}]/u', $text) === 1) {
            throw ValidationException::withMessages([$field => 'No en or em dashes, please.']);
        }
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }
}
