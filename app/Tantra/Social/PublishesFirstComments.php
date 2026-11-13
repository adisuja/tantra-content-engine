<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Dto\MediaItem;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\ThreadReplies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * tantra: the first comment (or, on Threads, the reply chain) under a post on
 * Instagram, Facebook, Threads and YouTube, from `meta.thread_replies`.
 *
 * The post is live before any comment goes, so a comment that fails never
 * fails the post: it is reported, logged and flagged on the post
 * (`meta.first_comment_failed`), the way UnipilePublisher treats LinkedIn
 * comments, and the chain stops there.
 */
trait PublishesFirstComments
{
    /**
     * @param  array<string, mixed>  $root  the published post: {id, url}
     * @param  callable(string, Collection<int, MediaItem>, array<string, mixed>, array<string, mixed>): array{id: string}  $postReply  text, media, previous segment, root
     * @param  list<string>  $posted  ids of the replies an earlier attempt already posted, skipped here
     * @param  (callable(list<string>): void)|null  $checkpoint  called with every reply id posted so far
     * @return array<string, mixed>
     */
    protected function withFirstComments(Post $post, array $root, callable $postReply, array $posted = [], ?callable $checkpoint = null): array
    {
        $replies = ThreadReplies::supports($post->platform) ? ThreadReplies::of($post->meta) : [];

        if ($replies === [] || blank($root['id'] ?? null)) {
            return $root;
        }

        $ids = array_values(array_slice($posted, 0, count($replies)));
        $previous = $ids === [] ? $root : ['id' => end($ids)];

        foreach (array_slice($replies, count($ids)) as $reply) {
            $text = (string) app(ContentSanitizer::class)->sanitize($reply['text'], $post->platform);

            try {
                $previous = $postReply($text, ThreadReplies::mediaItems($reply), $previous, $root);
                $ids[] = (string) $previous['id'];

                if ($checkpoint !== null) {
                    $checkpoint($ids);
                }
            } catch (Throwable $e) {
                report($e);
                Log::warning('tantra: first comment not posted', ['post_id' => $post->id, 'platform' => $post->platform->value, 'error' => $e->getMessage()]);
                $post->forceFill(['meta' => [...((array) $post->meta), 'first_comment_failed' => true]])->saveQuietly();

                break;
            }
        }

        return $ids === [] ? $root : [...$root, 'thread_reply_ids' => $ids];
    }
}
