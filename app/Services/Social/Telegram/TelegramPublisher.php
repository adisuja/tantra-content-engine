<?php

declare(strict_types=1);

namespace App\Services\Social\Telegram;

use App\Dto\MediaItem;
use App\Exceptions\Social\TelegramPublishException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\ContentSanitizer;
use App\Tantra\Composer\TantraMetaRules;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TelegramPublisher
{
    use HasSocialHttpClient;

    /**
     * Telegram caps media captions at 1024 chars (a text-only message allows
     * 4096). When the post is longer than a caption, the media is sent first and
     * the full text follows as its own message.
     */
    private const CAPTION_LIMIT = 1024;

    private const ALBUM_CHUNK = 10;

    /** tantra: options every message of the post is sent with (silent, protected). */
    private array $tantraSendOptions = [];

    /** tantra: whether photos and videos go out behind a spoiler. */
    private bool $tantraSpoiler = false;

    public function publish(Post $post): array
    {
        $this->validateContentLength($post);

        $account = $post->socialAccount;
        $chatId = (string) data_get($account->meta, 'chat_id');

        $content = app(ContentSanitizer::class)->forPost($post) ?? '';

        $media = $post->mediaItems->take(self::ALBUM_CHUNK);

        // tantra: send silently, protect from forwarding and saving, spoiler media.
        $this->tantraSendOptions = array_filter([
            'disable_notification' => data_get($post->meta, 'disable_notification') === true,
            'protect_content' => data_get($post->meta, 'protect_content') === true,
        ]);
        $this->tantraSpoiler = data_get($post->meta, 'has_spoiler') === true;

        $messageId = $media->isEmpty()
            ? $this->sendText($chatId, $content)
            : $this->sendWithMedia($chatId, $content, $media);

        $this->tantraAfterPost($post, $chatId, (int) $messageId); // tantra: poll and pin

        return [
            'id' => (string) $messageId,
            'url' => $this->buildPostUrl($account, $messageId),
        ];
    }

    private function sendText(string $chatId, string $text): int
    {
        $response = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        return (int) data_get($response->json(), 'result.message_id');
    }

    private function sendWithMedia(string $chatId, string $content, Collection $media): int
    {
        $fitsCaption = mb_strlen($content) <= self::CAPTION_LIMIT;
        $caption = $fitsCaption ? $content : '';

        $items = $media->map(fn (MediaItem $item) => $this->telegramMedia($item))->values()->all();

        $messageId = count($items) === 1
            ? $this->sendSingleMedia($chatId, $items[0], $caption)
            : $this->sendMediaGroup($chatId, $items, $caption);

        // Long text can't ride along as a caption — send it as a follow-up message.
        if (! $fitsCaption) {
            $this->sendText($chatId, $content);
        }

        return $messageId;
    }

    /**
     * @param  array{type: TelegramMediaType, url: string}  $item
     */
    private function sendSingleMedia(string $chatId, array $item, string $caption): int
    {
        $type = $item['type'];

        $response = $this->call($type->sendMethod(), [
            'chat_id' => $chatId,
            $type->value => $item['url'],
            'caption' => $caption,
            'parse_mode' => 'HTML',
            ...($this->tantraSpoiler && in_array($type->value, ['photo', 'video', 'animation'], true) ? ['has_spoiler' => true] : []), // tantra:
        ]);

        return (int) data_get($response->json(), 'result.message_id');
    }

    /**
     * @param  array<int, array{type: TelegramMediaType, url: string}>  $items
     */
    private function sendMediaGroup(string $chatId, array $items, string $caption): int
    {
        $group = [];

        foreach ($items as $index => $item) {
            $entry = ['type' => $item['type']->value, 'media' => $item['url']];

            if ($this->tantraSpoiler && in_array($entry['type'], ['photo', 'video'], true)) {
                $entry['has_spoiler'] = true; // tantra:
            }

            if ($index === 0 && $caption !== '') {
                $entry['caption'] = $caption;
                $entry['parse_mode'] = 'HTML';
            }

            $group[] = $entry;
        }

        $response = $this->call('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($group),
        ]);

        return (int) data_get($response->json(), 'result.0.message_id');
    }

    /**
     * @return array{type: TelegramMediaType, url: string}
     */
    private function telegramMedia(MediaItem $media): array
    {
        return ['type' => TelegramMediaType::for($media), 'url' => $media->url];
    }

    private function call(string $method, array $payload): Response
    {
        // tantra: every send carries the post's send options.
        if (str_starts_with($method, 'send')) {
            $payload = [...$payload, ...$this->tantraSendOptions];
        }

        $response = $this->socialHttp()->post(TelegramApi::endpoint($method), $payload);

        if ($response->failed() || data_get($response->json(), 'ok') !== true) {
            $this->handleApiError($response);
        }

        return $response;
    }

    /**
     * tantra: the poll after the post (sendPoll) and pinning the post. The post
     * is live by then, so neither ever fails it: a refusal is logged.
     */
    private function tantraAfterPost(Post $post, string $chatId, int $messageId): void
    {
        $options = TantraMetaRules::choices(data_get($post->meta, 'telegram_poll.options'));
        $question = trim((string) data_get($post->meta, 'telegram_poll.question'));

        if (count($options) >= 2 && $question !== '') {
            rescue(fn () => $this->call('sendPoll', [
                'chat_id' => $chatId,
                'question' => mb_substr($question, 0, 300),
                'options' => json_encode(array_map(fn (string $text): array => ['text' => $text], array_slice($options, 0, 10))),
            ]), fn (\Throwable $e) => Log::warning('tantra: Telegram poll not sent', ['post_id' => $post->id, 'error' => $e->getMessage()]), report: false);
        }

        if (data_get($post->meta, 'pin') === true && $messageId > 0) {
            rescue(fn () => $this->call('pinChatMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'disable_notification' => data_get($post->meta, 'disable_notification') === true,
            ]), fn (\Throwable $e) => Log::warning('tantra: Telegram pin failed', ['post_id' => $post->id, 'error' => $e->getMessage()]), report: false);
        }
    }

    private function buildPostUrl(SocialAccount $account, int $messageId): string
    {
        $username = (string) data_get($account->meta, 'username');

        if ($username !== '') {
            return "https://t.me/{$username}/{$messageId}";
        }

        // Private channels: t.me/c/<id without the -100 prefix>/<message_id>.
        $internalId = preg_replace('/^-100/', '', (string) data_get($account->meta, 'chat_id'));

        return "https://t.me/c/{$internalId}/{$messageId}";
    }

    private function handleApiError(Response $response): never
    {
        throw TelegramPublishException::fromApiResponse($response);
    }
}
