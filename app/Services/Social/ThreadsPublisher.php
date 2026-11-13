<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\ThreadsMediaContainerNotFoundException;
use App\Exceptions\Social\ThreadsPublishException;
use App\Models\Post;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\PostPlatformMetaRules;
use App\Tantra\Composer\TantraMetaRules;
use App\Tantra\Social\PublishesFirstComments;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class ThreadsPublisher
{
    use HasSocialHttpClient;
    use PublishesFirstComments; // tantra:

    private const int MEDIA_PUBLICATION_MAX_ATTEMPTS = 3;

    private const int MEDIA_PROCESSING_POLL_SECONDS = 3;

    private const int MEDIA_READY_GRACE_SECONDS = 2;

    private string $baseUrl;

    /**
     * Extra parameters for the post's own container (never carousel children).
     *
     * @var array<string, string>
     */
    private array $containerOptions = [];

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.threads.graph_api');
    }

    public function publish(Post $post): array
    {
        $this->validateContentLength($post);

        $content = app(ContentSanitizer::class)->forPost($post);

        $account = $post->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $userId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $media = $post->mediaItems;

        if ($post->content_type === ContentType::ThreadsGhostPost && $media->isNotEmpty()) {
            throw new ThreadsPublishException(
                userMessage: __('posts.form.warnings.text_only'),
                category: ErrorCategory::MediaFormat,
            );
        }

        $this->containerOptions = $post->content_type === ContentType::ThreadsGhostPost
            ? ['is_ghost_post' => 'true']
            : $this->postOptions($post);

        $root = $this->publishSegment($userId, $accessToken, $content, $media);

        // tantra: the reply chain under the post (ghost posts take none).
        if ($post->content_type === ContentType::ThreadsGhostPost) {
            return $root;
        }

        return $this->withFirstComments($post, $root, function (string $text, $replyMedia, array $previous) use ($userId, $accessToken): array {
            $this->containerOptions = ['reply_to_id' => (string) $previous['id']];

            return $this->publishSegment($userId, $accessToken, $text, $replyMedia);
        });
    }

    /**
     * tantra: one post of the chain (the root or a reply), by its media.
     *
     * @return array{id: string, url: ?string}
     */
    private function publishSegment(string $userId, string $accessToken, ?string $content, $media): array
    {
        // Text only post
        if ($media->isEmpty()) {
            if (empty($content)) {
                throw new ThreadsPublishException(
                    userMessage: 'Threads text posts require content. Please add text to your post.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            return $this->publishMediaWithRetry(
                fn (): array => $this->publishTextPost($userId, $accessToken, $content),
            );
        }

        $firstMedia = $media->first();
        $isVideo = $firstMedia->isVideo();

        // Single media
        if ($media->count() === 1) {
            if ($isVideo) {
                return $this->publishMediaWithRetry(
                    fn (): array => $this->publishVideoPost($userId, $accessToken, $content, $firstMedia),
                );
            }

            return $this->publishMediaWithRetry(
                fn (): array => $this->publishImagePost($userId, $accessToken, $content, $firstMedia),
            );
        }

        // Multiple media - carousel
        return $this->publishMediaWithRetry(
            fn (): array => $this->publishCarousel($userId, $accessToken, $content, $media),
        );
    }

    private function publishTextPost(string $userId, string $accessToken, string $content): array
    {
        // Step 1: Create container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'TEXT',
            'text' => $content,
            ...$this->containerOptions,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            Log::error('Threads container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the post. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        $this->waitForMediaProcessing($containerId, $accessToken);

        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishImagePost(string $userId, string $accessToken, ?string $content, $media): array
    {
        // Step 1: Create container
        $params = [
            'media_type' => 'IMAGE',
            'image_url' => $media->url,
            'text' => $content,
            ...$this->containerOptions,
            'access_token' => $accessToken,
        ];

        $alt = $media->altTextFor(Platform::Threads);

        if ($alt !== null) {
            $params['alt_text'] = $alt;
        }

        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", $params);

        if ($containerResponse->failed()) {
            Log::error('Threads image container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the image. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Wait for image processing
        $this->waitForMediaProcessing($containerId, $accessToken);

        // Step 3: Publish
        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishVideoPost(string $userId, string $accessToken, ?string $content, $media): array
    {
        // Step 1: Create container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'VIDEO',
            'video_url' => $media->url,
            'text' => $content,
            ...$this->containerOptions,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            Log::error('Threads video container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the video. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Wait for video processing
        $this->waitForMediaProcessing($containerId, $accessToken);

        // Step 2: Publish
        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishCarousel(string $userId, string $accessToken, ?string $content, $mediaCollection): array
    {
        // Step 1: Create containers for each media item
        $childContainers = [];

        foreach ($mediaCollection as $media) {
            $isVideo = $media->isVideo();

            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            if ($isVideo) {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $media->url;
            } else {
                $params['media_type'] = 'IMAGE';
                $params['image_url'] = $media->url;

                $alt = $media->altTextFor(Platform::Threads);

                if ($alt !== null) {
                    $params['alt_text'] = $alt;
                }
            }

            $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", $params);

            if ($containerResponse->failed()) {
                Log::error('Threads carousel item creation failed', [
                    'body' => $this->redactResponseBody($containerResponse->body()),
                ]);

                continue;
            }

            $childId = $containerResponse->json()['id'] ?? null;

            if (! $childId) {
                Log::error('Threads carousel item creation returned no ID', ['body' => $this->redactResponseBody($containerResponse->body())]);

                continue;
            }

            // Wait for media processing (both images and videos)
            $this->waitForMediaProcessing($childId, $accessToken);

            $childContainers[] = $childId;
        }

        if (empty($childContainers)) {
            throw new ThreadsPublishException(
                userMessage: 'Failed to create any carousel items',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Create carousel container
        $carouselResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'CAROUSEL',
            'text' => $content,
            'children' => implode(',', $childContainers),
            ...$this->containerOptions,
            'access_token' => $accessToken,
        ]);

        if ($carouselResponse->failed()) {
            Log::error('Threads carousel container creation failed', [
                'body' => $this->redactResponseBody($carouselResponse->body()),
            ]);
            $this->handleApiError($carouselResponse);
        }

        $carouselId = $carouselResponse->json()['id'] ?? null;

        if (! $carouselId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the carousel. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        $this->waitForMediaProcessing($carouselId, $accessToken);

        // Step 3: Publish carousel
        return $this->publishContainer($userId, $accessToken, $carouselId);
    }

    /**
     * @param  callable(): array{id: string, url: ?string}  $publish
     * @return array{id: string, url: ?string}
     */
    private function publishMediaWithRetry(callable $publish): array
    {
        for ($attempt = 1; $attempt <= self::MEDIA_PUBLICATION_MAX_ATTEMPTS; $attempt++) {
            try {
                return $publish();
            } catch (ThreadsMediaContainerNotFoundException $exception) {
                if ($attempt === self::MEDIA_PUBLICATION_MAX_ATTEMPTS) {
                    throw $exception;
                }

                Log::warning('Threads media container was not found; recreating publication flow', [
                    'attempt' => $attempt,
                    'max_attempts' => self::MEDIA_PUBLICATION_MAX_ATTEMPTS,
                    ...$exception->context(),
                ]);
            }
        }

        throw new ThreadsPublishException(
            userMessage: 'Threads did not accept the post. Please publish again.',
            category: ErrorCategory::ServerError,
        );
    }

    private function publishContainer(string $userId, string $accessToken, string $containerId): array
    {
        $publishResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken,
        ]);

        if ($publishResponse->failed()) {
            if (ThreadsMediaContainerNotFoundException::matches($publishResponse)) {
                throw ThreadsMediaContainerNotFoundException::fromApiResponse($publishResponse);
            }

            Log::error('Threads publish failed', [
                'status' => $publishResponse->status(),
                'body' => $this->redactResponseBody($publishResponse->body()),
            ]);

            $this->handleApiError($publishResponse);
        }

        $mediaId = (string) $publishResponse->json('id');

        if (blank($mediaId)) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the post. Please publish again.',
                category: ErrorCategory::ServerError,
            );
        }

        try {
            $permalinkResponse = $this->socialHttp()->get("{$this->baseUrl}/{$mediaId}", [
                'fields' => 'permalink',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return [
                'id' => $mediaId,
                'url' => null,
            ];
        }

        $permalink = $permalinkResponse->json('permalink');

        return [
            'id' => $mediaId,
            'url' => $permalink,
        ];
    }

    private function waitForMediaProcessing(string $containerId, string $accessToken, int $maxAttempts = 30): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $statusResponse = $this->socialHttp()->get("{$this->baseUrl}/{$containerId}", [
                'fields' => 'status,error_message',
                'access_token' => $accessToken,
            ]);

            if ($statusResponse->failed()) {
                Log::warning('Threads status check failed', [
                    'container_id' => $containerId,
                    'attempt' => $i,
                    'body' => $this->redactResponseBody($statusResponse->body()),
                ]);
                Sleep::for(self::MEDIA_PROCESSING_POLL_SECONDS)->seconds();

                continue;
            }

            $data = $statusResponse->json();
            $status = data_get($data, 'status', 'UNKNOWN');

            if ($status === 'FINISHED') {
                Sleep::for(self::MEDIA_READY_GRACE_SECONDS)->seconds();

                return;
            }

            if ($status === 'ERROR') {
                $errorMessage = data_get($data, 'error_message', 'Unknown error');
                throw new ThreadsPublishException(
                    userMessage: 'Threads media processing failed. Please try a different file.',
                    category: ErrorCategory::ServerError,
                );
            }

            Sleep::for(self::MEDIA_PROCESSING_POLL_SECONDS)->seconds();
        }

        Log::warning('Threads media processing timeout', ['container_id' => $containerId]);
        throw new ThreadsPublishException(
            userMessage: 'Threads took too long to process the media. Please try again.',
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * @return array<string, string>
     */
    private function postOptions(Post $post): array
    {
        $topicTag = PostPlatformMetaRules::threadsTopicTag((string) data_get($post->meta, 'topic_tag'));

        return [
            ...($topicTag === '' ? [] : ['topic_tag' => $topicTag]),
            ...$this->tantraOptions($post), // tantra:
        ];
    }

    /**
     * tantra: who may reply, the AI label, and per shape a poll and link card
     * (text posts) or a spoiler (media posts).
     *
     * @return array<string, string>
     */
    private function tantraOptions(Post $post): array
    {
        $meta = $post->meta;
        $textOnly = $post->mediaItems->isEmpty();
        $replyControl = data_get($meta, 'reply_control');
        $options = TantraMetaRules::choices(data_get($meta, 'threads_poll.options'));
        $link = data_get($meta, 'link_attachment');

        return array_filter([
            'reply_control' => in_array($replyControl, TantraMetaRules::REPLY_CONTROL, true) && $replyControl !== 'everyone' ? $replyControl : null,
            'is_made_with_ai' => data_get($meta, 'is_ai_generated') === true ? 'true' : null,
            'link_attachment' => $textOnly && is_string($link) && $link !== '' ? $link : null,
            'poll_attachment' => $textOnly && count($options) >= 2
                ? (string) json_encode(array_combine(array_slice(['option_a', 'option_b', 'option_c', 'option_d'], 0, count($options)), array_slice($options, 0, 4)))
                : null,
            'is_spoiler_media' => ! $textOnly && data_get($meta, 'spoiler') === true ? 'true' : null,
        ], fn (?string $value): bool => $value !== null);
    }

    private function handleApiError(Response $response): never
    {
        throw ThreadsPublishException::fromApiResponse($response);
    }
}
