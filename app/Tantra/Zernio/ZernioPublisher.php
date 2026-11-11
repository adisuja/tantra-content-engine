<?php

declare(strict_types=1);

namespace App\Tantra\Zernio;

use App\Dto\MediaItem;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\ThreadReplies;
use Illuminate\Http\Client\Response;

/**
 * tantra: publishes a Tantra LinkedIn page post through Zernio (PRD 10.4).
 *
 * TryPost's scheduler owns the timing, so every post goes out with
 * `publishNow`. A Zernio post is created at most once per Engine post: the
 * Idempotency-Key is stable (`tantra-<post id>`) and, once a Zernio id is
 * stored on the post, every later attempt reads or retries that post instead
 * of creating another one.
 */
class ZernioPublisher
{
    /** Zernio error categories that no retry can fix. */
    private const PERMANENT = ['user_content', 'user_abuse', 'platform_rejected', 'auth_expired', 'account_issue', 'quota_exhausted'];

    public function __construct(private readonly ZernioClient $zernio) {}

    /**
     * @return array{id: string, url: ?string}
     */
    public function publish(Post $post): array
    {
        $stored = data_get($post->meta, 'zernio_post_id');

        if (filled($stored)) {
            return $this->resume($post, (string) $stored);
        }

        $response = $this->zernio->createPost($this->payload($post), 'tantra-'.$post->id);

        if ($response->status() === 409 && filled($existing = $response->json('details.existingPostId'))) {
            $this->remember($post, (string) $existing);

            return $this->resume($post, (string) $existing);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        $zernioPost = (array) ($response->json('post') ?? []);
        $id = (string) ($zernioPost['_id'] ?? $zernioPost['id'] ?? '');
        if ($id === '') {
            throw new PlatformUnavailableException('Zernio answered without a post id.', $response->status());
        }

        $this->remember($post, $id);

        return $this->settle($zernioPost, $id);
    }

    /**
     * The Zernio post this Engine post already created: done, retry it, or wait.
     *
     * @return array{id: string, url: ?string}
     */
    private function resume(Post $post, string $id): array
    {
        $response = $this->zernio->getPost($id);
        if ($response->failed()) {
            throw $response->status() >= 500 || $response->status() === 429
                ? new PlatformUnavailableException('Zernio could not read the post yet.', $response->status())
                : $this->failure($response);
        }

        $zernioPost = (array) ($response->json('post') ?? $response->json());

        if (in_array($zernioPost['status'] ?? null, ['failed', 'partial'], true)) {
            $retried = $this->zernio->retryPost($id);
            if ($retried->failed()) {
                throw $this->failure($retried);
            }
            $zernioPost = (array) ($retried->json('post') ?? $zernioPost);
        }

        return $this->settle($zernioPost, $id);
    }

    /**
     * @param  array<string, mixed>  $zernioPost
     * @return array{id: string, url: ?string}
     */
    private function settle(array $zernioPost, string $id): array
    {
        $target = collect((array) ($zernioPost['platforms'] ?? []))->first() ?? [];
        $status = (string) ($target['status'] ?? $zernioPost['status'] ?? '');
        $url = $target['platformPostUrl'] ?? $target['publishedUrl'] ?? null;

        if ($status === 'published' || ($zernioPost['status'] ?? null) === 'published') {
            return ['id' => $id, 'url' => is_string($url) && $url !== '' ? $url : null];
        }

        if ($status === 'failed') {
            $message = (string) ($target['errorMessage'] ?? $target['error'] ?? 'LinkedIn refused the post.');
            $category = $target['errorCategory'] ?? null;

            if ($category === 'platform_rate_limit') {
                throw new ZernioPublishException('Zernio: '.$message, ErrorCategory::RateLimit);
            }
            if (in_array($category, self::PERMANENT, true)) {
                throw new ZernioPublishException('Zernio: '.$message, ZernioPublishException::fromText($message, $category));
            }
        }

        // Still publishing, or a failure that may clear: the next attempt reads
        // the same Zernio post and retries it (PRD 10.4 step 4).
        $stillGoing = $status === '' || in_array($status, ['pending', 'processing', 'publishing', 'uploading', 'scheduled'], true);

        throw new PlatformUnavailableException(
            'The Zernio post is '.($status ?: 'publishing').'.',
            null,
            ['zernio_post_id' => $id],
            // Zernio publishes in seconds: check again in a minute while it is still going.
            retryDelaySeconds: $stillGoing ? 60 : null,
            maxRetries: $stillGoing ? 20 : null,
        );
    }

    /** @return array<string, mixed> */
    private function payload(Post $post): array
    {
        $account = $post->socialAccount;
        $specific = array_filter([
            'firstComment' => ThreadReplies::of($post->meta)[0]['text'] ?? null,
            'organizationUrn' => data_get($account?->meta, 'organization_urn'),
        ], fn ($value): bool => filled($value));

        $media = $post->mediaItems->map(fn (MediaItem $item): array => [
            'url' => $item->url,
            'type' => match (true) {
                $item->isDocument() => 'document',
                $item->isVideo() => 'video',
                str_contains((string) $item->mime_type, 'gif') => 'gif',
                default => 'image',
            },
        ])->values()->all();

        if ($post->mediaItems->contains(fn (MediaItem $item): bool => $item->isDocument())) {
            $specific['documentTitle'] = (string) (data_get($post->meta, 'document_title')
                ?: $post->mediaItems->first(fn (MediaItem $item): bool => $item->isDocument())?->original_filename
                ?: 'Document');
        }

        return array_filter([
            'content' => app(ContentSanitizer::class)->forPost($post),
            'mediaItems' => $media ?: null,
            'publishNow' => true,
            'platforms' => [[
                'platform' => 'linkedin',
                'accountId' => (string) $account?->platform_user_id,
                'platformSpecificData' => $specific ?: (object) [],
            ]],
        ], fn ($value): bool => $value !== null);
    }

    private function remember(Post $post, string $id): void
    {
        $post->forceFill(['meta' => [...((array) $post->meta), 'zernio_post_id' => $id]])->saveQuietly();
    }

    private function failure(Response $response): \Throwable
    {
        if ($response->serverError()) {
            return new PlatformUnavailableException('Zernio answered '.$response->status().'.', $response->status());
        }

        return ZernioPublishException::fromApiResponse($response);
    }
}
