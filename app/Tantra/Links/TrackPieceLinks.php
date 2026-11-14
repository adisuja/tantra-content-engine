<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * tantra: tracks the links of one saved piece. Queued after every save that
 * brings in an untracked link (a batch push, an idea, a composer edit, an
 * owner's message edit); `tantra:track-links` sweeps up anything it missed.
 */
final class TrackPieceLinks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public int $uniqueFor = 120;

    public function __construct(public readonly string $type, public readonly string $id)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->type.':'.$this->id;
    }

    public function handle(CrmTracking $crm): void
    {
        if (! PieceLinks::enabled()) {
            return;
        }
        $links = new PieceLinks($crm);
        match ($this->type) {
            'post' => ($post = Post::query()->find($this->id)) && self::open($post) ? $links->post($post) : 0,
            'review_item' => ($item = ReviewItem::query()->find($this->id)) ? $links->reviewItem($item) : 0,
            'idea' => ($meta = IdeaMeta::query()->find($this->id)) ? $links->idea($meta) : 0,
            default => 0,
        };
        if ($links->errors !== []) {
            // Retry later; the sweep catches it if every try fails.
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    /** Published posts keep the link they went out with. */
    public static function open(Post $post): bool
    {
        return in_array($post->status, [PostStatus::Draft, PostStatus::PendingApproval, PostStatus::Scheduled], true);
    }

    /** Wires the saved hooks: only saves whose text holds an untracked link queue a job. */
    public static function listen(): void
    {
        Post::saved(function (Post $post): void {
            if (PieceLinks::enabled() && self::open($post) && ($post->wasChanged(['content', 'meta']) || $post->wasRecentlyCreated)
                && PieceLinks::hasUntracked((string) $post->content, ...array_map(fn ($r) => (string) data_get($r, 'text', ''), (array) data_get($post->meta, 'thread_replies', [])))) {
                self::dispatch('post', (string) $post->id);
            }
        });
        ReviewItem::saved(function (ReviewItem $item): void {
            if (PieceLinks::enabled() && ($item->wasChanged(['payload', 'message_overrides']) || $item->wasRecentlyCreated)
                && PieceLinks::hasUntracked(json_encode([data_get($item->payload, 'text'), data_get($item->payload, 'first_comment'), data_get($item->payload, 'thread'), data_get($item->payload, 'messages'), $item->message_overrides], JSON_UNESCAPED_SLASHES) ?: '')) {
                self::dispatch('review_item', (string) $item->id);
            }
        });
        IdeaMeta::saved(function (IdeaMeta $meta): void {
            if (PieceLinks::enabled() && $meta->state === IdeaMeta::STATE_IDEA && ($meta->wasChanged('preview') || $meta->wasRecentlyCreated)
                && PieceLinks::hasUntracked(json_encode([data_get($meta->preview, 'text'), data_get($meta->preview, 'first_comment'), data_get($meta->preview, 'thread')], JSON_UNESCAPED_SLASHES) ?: '')) {
                self::dispatch('idea', (string) $meta->idea_id);
            }
        });
    }
}
