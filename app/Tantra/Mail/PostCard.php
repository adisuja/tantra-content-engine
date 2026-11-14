<?php

declare(strict_types=1);

namespace App\Tantra\Mail;

use App\Dto\MediaItem;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * tantra: the data for one post card in an email (kit/post-card.blade.php):
 * who posts it where and when, the copy, the first comment and the media,
 * with every picture an email-sized copy on the app's own domain.
 */
final class PostCard
{
    public const int TEXT_CHARS = 420;

    public const int COMMENT_CHARS = 220;

    /** @return array<string, mixed> */
    public static function fromReviewItem(ReviewItem $item, ?string $timezone = null): array
    {
        $posts = $item->posts();
        /** @var Post|null $post */
        $post = $posts->sortBy('scheduled_at')->first();
        $channel = $post?->socialAccount ?? ($item->workspace ? ImportBatch::channelFor($item->workspace, (string) $item->platform) : null);
        $payload = (array) ($item->payload ?? []);
        $manifest = (array) ($item->media_manifest ?? []);

        $when = match (true) {
            $post?->scheduled_at !== null => self::when($post->scheduled_at, $timezone),
            $item->planned_date !== null && filled($item->planned_time) => self::when(CarbonImmutable::parse($item->planned_date->toDateString().' '.$item->planned_time, $item->timezone ?: 'UTC'), $timezone),
            $item->planned_date !== null => $item->planned_date->format('D j M'),
            default => null,
        };

        [$text, $more] = Kit::clamp((string) ($payload['text'] ?? $post?->content ?? ''), self::TEXT_CHARS);
        [$comment] = Kit::clamp((string) ($payload['first_comment'] ?? ''), self::COMMENT_CHARS);

        return [
            'network' => Kit::networkOf($channel?->platform ?? $item->platform),
            'network_label' => Kit::networkLabel($channel?->platform ?? $item->platform),
            'icon' => Kit::networkIcon($channel?->platform ?? $item->platform),
            'account' => $channel?->display_name ?: ($post?->platform_name ?: Kit::networkLabel($item->platform)),
            'avatar' => self::avatar($channel),
            'when' => $when,
            'badge' => $item->revision > 1 ? 'New version' : null,
            'text' => $text,
            'more' => $more,
            'first_comment' => $comment !== '' ? $comment : null,
            'media' => self::media((string) ($manifest['type'] ?? ''), array_values(array_filter((array) ($manifest['previews'] ?? []), 'is_string')), $post?->media_items ?? collect()),
            'url' => $item->shareUrl(),
            'link_label' => 'Review this post',
        ];
    }

    /** @return array<string, mixed> */
    public static function fromPost(Post $post, ?string $timezone = null, ?string $url = null, string $linkLabel = 'Open the post'): array
    {
        $post->loadMissing('socialAccount');
        $channel = $post->socialAccount;
        [$text, $more] = Kit::clamp((string) $post->content, self::TEXT_CHARS);
        $replies = (array) data_get($post->meta, 'thread_replies', []);
        [$comment] = Kit::clamp((string) data_get($replies, '0.text', ''), self::COMMENT_CHARS);
        $at = $post->published_at ?? $post->scheduled_at;

        return [
            'network' => Kit::networkOf($post->platform),
            'network_label' => Kit::networkLabel($post->platform),
            'icon' => Kit::networkIcon($post->platform),
            'account' => $channel?->display_name ?: ($post->platform_name ?: Kit::networkLabel($post->platform)),
            'avatar' => self::avatar($channel),
            'when' => $at ? self::when($at, $timezone) : null,
            'badge' => null,
            'text' => $text,
            'more' => $more,
            'first_comment' => $comment !== '' ? $comment : null,
            'media' => self::media('', [], $post->media_items ?? collect()),
            'url' => $url ?? Kit::appUrl('/posts/'.$post->id.'/edit'),
            'link_label' => $linkLabel,
        ];
    }

    public static function when(CarbonInterface $at, ?string $timezone): string
    {
        $tz = $timezone ?: (string) config('app.timezone', 'UTC');
        $local = $at->copy()->setTimezone($tz);

        return $local->format('D j M, g:i A').' '.$local->format('T');
    }

    private static function avatar(?SocialAccount $channel): ?string
    {
        // avatar_url holds a path on the media disk (SocialAccount::avatarUrl); emails get a small square copy.
        $path = $channel?->getRawOriginal('avatar_url');
        if (! is_string($path) || $path === '') {
            return null;
        }

        return str_starts_with($path, 'https://') ? $path : MailImage::url($path, 'thumb');
    }

    /**
     * @param  list<string>  $previews  storage paths the content kit rendered (slides, a video poster)
     * @param  Collection<int, MediaItem>  $items  the post's own media
     * @return array<string, mixed>
     */
    public static function media(string $type, array $previews, Collection $items): array
    {
        $video = $items->first(fn (MediaItem $m): bool => $m->isVideo());
        if ($type === 'video' || $video !== null) {
            return [
                'kind' => 'video',
                'cover' => MailImage::url($previews[0] ?? null) ?? Kit::asset('tantra/email/video-poster.jpg'),
                'w' => 520, 'h' => 292,
                'alt' => 'Video poster',
            ];
        }

        $gif = $items->first(fn (MediaItem $m): bool => strtolower((string) $m->mime_type) === 'image/gif');
        if ($gif !== null || $type === 'gif') {
            $path = $gif?->path ?? ($previews[0] ?? null);

            return ['kind' => 'gif', 'cover' => MailImage::url($path, 'gif'), 'w' => 520, 'alt' => 'Animated image'];
        }

        $paths = $previews !== [] ? $previews : $items->filter(fn (MediaItem $m): bool => $m->isImage())->pluck('path')->filter()->values()->all();
        if ($paths === []) {
            return ['kind' => 'none'];
        }
        if (count($paths) === 1) {
            return ['kind' => 'image', 'cover' => MailImage::url($paths[0]), 'w' => 520, 'alt' => 'Post image'];
        }

        return [
            'kind' => 'carousel',
            'cover' => MailImage::url($paths[0]),
            'w' => 520,
            'alt' => 'Carousel cover, slide 1 of '.count($paths),
            'strip' => array_values(array_filter(array_map(fn (string $p): ?string => MailImage::url($p, 'thumb'), array_slice($paths, 1, 4)))),
            'count' => count($paths),
        ];
    }
}
