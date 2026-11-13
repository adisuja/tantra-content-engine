<?php

declare(strict_types=1);

namespace App\Tantra\Unipile;

use App\Dto\MediaItem;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\ThreadReplies;
use App\Support\UrlDetector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: publishes a post on a teammate's LinkedIn profile through the account
 * they connected in Tantra's Unipile (Adi, 2026-11-13, PRD D2).
 *
 * Unipile has no idempotency key, so the Unipile post id is stored on the
 * Engine post the moment it exists: a retry after that never posts again, it
 * only finishes the first comment. Every publish is logged.
 */
class UnipilePublisher
{
    /** Unipile refuses a document title over 58 characters. */
    public const int DOCUMENT_TITLE_MAX = 58;

    public function __construct(private readonly UnipileClient $unipile) {}

    /**
     * @return array{id: string, url: ?string, thread_reply_ids?: list<string>}
     */
    public function publish(Post $post): array
    {
        $accountId = (string) $post->socialAccount?->platform_user_id;
        if ($accountId === '') {
            throw new UnipilePublishException('This LinkedIn channel needs reconnecting.', ErrorCategory::Permission);
        }

        $meta = (array) $post->meta;
        $id = (string) ($meta['unipile_post_id'] ?? '');
        $url = $meta['unipile_post_url'] ?? null;

        if ($id === '') {
            // An earlier attempt went out and never told us how it ended: LinkedIn
            // may have the post. Stop rather than risk a second copy.
            if (filled($meta['unipile_attempt_at'] ?? null)) {
                throw self::maybePosted();
            }
            [$id, $url] = $this->create($post, $accountId);
        }

        $result = ['id' => $id, 'url' => is_string($url) && $url !== '' ? $url : null];
        $replyIds = $this->firstComment($post, $accountId, $id);
        if ($replyIds !== []) {
            $result['thread_reply_ids'] = $replyIds;
        }

        return $result;
    }

    /** @return array{0: string, 1: ?string} */
    private function create(Post $post, string $accountId): array
    {
        $payload = $this->payload($post);
        $this->mark($post, ['unipile_attempt_at' => now()->toIso8601ZuluString()]);

        try {
            $response = $this->unipile->createPost($accountId, $payload);
        } catch (ConnectionException $e) {
            // Refused or unresolved: nothing reached Unipile, a retry is safe.
            if (preg_match('/cURL error (6|7)\b/', $e->getMessage()) === 1) {
                $this->mark($post, ['unipile_attempt_at' => null]);
                throw new PlatformUnavailableException('LinkedIn could not be reached. Trying again shortly.');
            }
            throw self::maybePosted();
        }

        if ($response->successful()) {
            $id = (string) ($response->json('id') ?? '');
            if ($id === '') {
                throw self::maybePosted();
            }
            $url = $response->json('share_url');
            $this->mark($post, ['unipile_post_id' => $id, 'unipile_post_url' => $url, 'unipile_attempt_at' => null]);
            Log::info('tantra: published through Unipile', ['post_id' => $post->id, 'account' => $accountId, 'unipile_post_id' => $id]);

            return [$id, is_string($url) ? $url : null];
        }

        // A clear refusal: nothing was posted.
        if ($response->status() === 429 || $response->clientError()) {
            $this->mark($post, ['unipile_attempt_at' => null]);
            throw UnipilePublishException::fromApiResponse($response);
        }

        throw self::maybePosted();
    }

    /** @return list<string> */
    private function firstComment(Post $post, string $accountId, string $postId): array
    {
        $text = ThreadReplies::of($post->meta)[0]['text'] ?? null;
        if (blank($text)) {
            return [];
        }

        $done = (string) data_get($post->meta, 'unipile_first_comment_id', '');
        if ($done !== '') {
            return [$done];
        }

        try {
            $commentId = $this->unipile->comment($accountId, $postId, (string) $text);
        } catch (UnipileException $e) {
            $status = $e->response->status();
            $tries = (int) data_get($post->meta, 'unipile_first_comment_tries', 0) + 1;
            // Busy or down: retry the job (the stored post id means only the comment goes again), at most three times.
            if (($status === 429 || $status >= 500) && $tries < 3) {
                $this->mark($post, ['unipile_first_comment_tries' => $tries]);
                throw new PlatformUnavailableException('The post is live; its first comment will be tried again.', $status);
            }

            return $this->commentLost($post, $e);
        } catch (Throwable $e) {
            // A timeout may have posted it: never risk a second comment.
            return $this->commentLost($post, $e);
        }
        if ($commentId === '') {
            return $this->commentLost($post, null);
        }
        $this->mark($post, ['unipile_first_comment_id' => $commentId, 'unipile_first_comment_failed' => null]);

        return [$commentId];
    }

    /** The post is live and stays Published; the missing first comment is flagged and logged for the team. */
    private function commentLost(Post $post, ?Throwable $e): array
    {
        if ($e !== null) {
            report($e);
        }
        $this->mark($post, ['unipile_first_comment_failed' => true]);
        Log::warning('tantra: first comment not posted through Unipile', ['post_id' => $post->id, 'error' => $e?->getMessage()]);

        return [];
    }

    private static function maybePosted(): UnipilePublishException
    {
        return new UnipilePublishException('LinkedIn did not confirm this post. Check the profile first: if it is not there, duplicate this post and schedule the copy, so it never goes out twice.', ErrorCategory::Unknown);
    }

    /** @param array<string, mixed> $values */
    private function mark(Post $post, array $values): void
    {
        $post->forceFill(['meta' => array_filter([...((array) $post->meta), ...$values], fn ($v): bool => $v !== null)])->saveQuietly();
    }

    /** @return array<string, mixed> */
    private function payload(Post $post): array
    {
        $attachments = $post->mediaItems->map(fn (MediaItem $item): array => [
            'content' => base64_encode($this->bytes($item)),
            'content_type' => (string) ($item->mime_type ?: 'application/octet-stream'),
            'filename' => (string) ($item->original_filename ?: basename($item->path) ?: 'file'),
        ])->values()->all();

        $text = (string) app(ContentSanitizer::class)->forPost($post);
        $document = $post->mediaItems->first(fn (MediaItem $item): bool => $item->isDocument());

        return array_filter([
            'text' => $text,
            'attachments' => $attachments ?: null,
            // The title shown on a PDF document post; Unipile takes up to 58 characters.
            'document_title' => $document === null ? null : Str::limit(
                (string) (data_get($post->meta, 'document_title') ?: $document->original_filename ?: 'Document'),
                self::DOCUMENT_TITLE_MAX,
                '',
            ),
            // The link card of a text post; LinkedIn shows it only on a post without media.
            'external_link' => $attachments === [] && $post->attachesLinkPreview() ? UrlDetector::firstUrl($text) : null,
        ], fn ($v): bool => $v !== null);
    }

    private function bytes(MediaItem $item): string
    {
        if ($item->path !== '' && Storage::exists($item->path)) {
            return (string) Storage::get($item->path);
        }

        $response = Http::timeout(60)->get($item->url);
        if ($response->failed()) {
            throw new PlatformUnavailableException('Could not read the media for this post.', $response->status());
        }

        return $response->body();
    }
}
