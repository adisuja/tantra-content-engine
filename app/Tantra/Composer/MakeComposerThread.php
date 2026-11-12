<?php

declare(strict_types=1);

namespace App\Tantra\Composer;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Tantra\Ai\TantraAi;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: a post written in the composer was scheduled in a Tantra workspace.
 * It has no engine thread, so write one and keep it on a review item in the
 * workspace's "Written in the composer" batch, where the review owner can edit
 * it before the post goes out. Only while boosting is on: nothing else uses it.
 */
class MakeComposerThread implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BATCH = 'composer';

    public int $tries = 2;

    public function __construct(public string $postId)
    {
        $this->onQueue(Boost::QUEUE);
    }

    public function handle(): void
    {
        $post = Post::query()->with('workspace.account')->find($this->postId);
        if ($post === null || $post->status !== PostStatus::Scheduled || $post->post_group_id === null || ! Boost::enabled()
            || ReviewItem::query()->where('post_group_id', $post->post_group_id)->exists()) {
            return;
        }

        $settings = WorkspaceSettings::query()->find($post->workspace_id);
        if ($settings?->brand_profile_key === null || $post->workspace === null) {
            return;
        }

        $teammates = self::teammates($post->workspace->account_id, $settings->brand_profile_key);
        if ($teammates === []) {
            return;
        }

        try {
            $response = TantraAi::prompt(new ComposerThreadWriter($settings->brand_profile_key, $teammates), "The post:\n\n".trim((string) $post->content), $post->workspace->account_id);
            $thread = self::thread((array) ($response['comments'] ?? []), array_keys($teammates), $settings->brand_profile_key);
        } catch (Throwable $e) {
            report($e);

            return;
        }
        if ($thread === []) {
            return;
        }

        $batch = Batch::query()->firstOrCreate(
            ['workspace_id' => $post->workspace_id, 'engine_batch_id' => self::BATCH],
            ['title' => 'Written in the composer', 'imported_at' => now(), 'status' => 'in_review'],
        );

        $item = ReviewItem::query()->create([
            'account_id' => $post->workspace->account_id,
            'batch_id' => $batch->id,
            'workspace_id' => $post->workspace_id,
            'engine_batch_id' => self::BATCH,
            'engine_post_id' => 'C'.strtoupper(Str::substr(md5((string) $post->post_group_id), 0, 10)),
            'platform' => 'linkedin',
            'post_group_id' => $post->post_group_id,
            'source' => 'composer',
            'planned_date' => $post->scheduled_at?->toDateString(),
            'planned_time' => $post->scheduled_at?->format('H:i'),
            'timezone' => 'UTC',
            'short' => Str::limit(trim((string) $post->content), 80),
            'payload' => ['text' => (string) $post->content, 'engagement_comments' => $thread],
            'review_status' => ReviewStatus::Scheduled,
        ]);
        $item->record(EventKind::Imported, null, 'Team thread written for a post from the composer. Edit or remove any entry before it goes out.');
    }

    /**
     * Profile key => expertise for everyone else on the Account who can comment.
     *
     * @return array<string, string>
     */
    private static function teammates(string $accountId, string $author): array
    {
        $out = [];
        $settings = WorkspaceSettings::query()->whereHas('workspace', fn ($q) => $q->where('account_id', $accountId))->get();
        foreach ($settings as $s) {
            if ($s->brand_profile_key === null || $s->brand_profile_key === $author || isset($out[$s->brand_profile_key])) {
                continue;
            }
            $ownerId = ReviewGate::reviewOwnerIdFor($s->workspace_id, null);
            $profile = $ownerId ? MemberProfile::query()->find($ownerId) : null;
            if ($profile !== null && $profile->blocker() === null) {
                $out[$s->brand_profile_key] = (string) ($profile->expertise ?: 'a teammate at Tantra');
            }
        }

        return array_slice($out, 0, 8, true);
    }

    /**
     * The model's comments as an engine thread: each comment, then the author's
     * reply to it. Entries with an unknown person, no text or a dash are dropped.
     *
     * @param  list<mixed>  $comments
     * @param  list<string>  $people
     * @return list<array{by: string, text: string, reply_to: ?int}>
     */
    public static function thread(array $comments, array $people, string $author): array
    {
        $thread = [];
        $seen = [];
        foreach ($comments as $c) {
            $by = (string) data_get($c, 'by');
            $text = trim((string) data_get($c, 'text'));
            $reply = trim((string) data_get($c, 'reply'));
            if (! in_array($by, $people, true) || isset($seen[$by]) || $text === '' || preg_match('/[\x{2013}\x{2014}]/u', $text.$reply)) {
                continue;
            }
            $seen[$by] = true;
            $thread[] = ['by' => $by, 'text' => $text, 'reply_to' => null];
            if ($reply !== '') {
                $thread[] = ['by' => $author, 'text' => $reply, 'reply_to' => count($thread) - 1];
            }
        }

        return $thread;
    }
}
