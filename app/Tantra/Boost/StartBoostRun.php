<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Tantra\Review\Models\ReviewItem;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** tantra: a post went live; plan its team thread and queue every action (PRD 10.5 step 4). */
class StartBoostRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $postId)
    {
        $this->onQueue(Boost::QUEUE);
    }

    public function handle(): void
    {
        $post = Post::query()->find($this->postId);
        if ($post === null || $post->status !== PostStatus::Published || ! Boost::enabled()) {
            return;
        }

        $item = $post->post_group_id ? ReviewItem::query()->where('post_group_id', $post->post_group_id)->first() : null;
        if ($item === null || data_get($item->payload, 'engagement_comments') === null) {
            return;
        }

        $run = BoostRun::query()->firstOrCreate(['post_id' => $post->id], [
            'review_item_id' => $item->id,
            'published_at' => $post->published_at ?? now(),
            'status' => 'running',
        ]);
        if (! $run->wasRecentlyCreated) {
            return;
        }

        $actions = (new BoostPlanner)->plan($run, CarbonImmutable::now());

        foreach ($actions as $action) {
            if ($action->status === 'queued') {
                RunBoostAction::dispatch($action->id)->delay($action->run_at);
            }
        }

        if (collect($actions)->every(fn (BoostAction $a): bool => $a->status === 'skipped')) {
            $run->forceFill(['status' => 'skipped', 'skipped_reason' => 'nobody in the thread can post yet'])->save();
        }
    }
}
