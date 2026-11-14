<?php

declare(strict_types=1);

namespace App\Tantra\Ideas;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * tantra: the networks an idea can be for (PRD 25.8), the channels that
 * publish each one, and the content type a draft gets on that channel.
 */
final class Networks
{
    /** @var array<string, array{label: string, platforms: list<Platform>}> */
    private const MAP = [
        'linkedin' => ['label' => 'LinkedIn', 'platforms' => [Platform::LinkedIn, Platform::UnipileLinkedIn]],
        'linkedin_page' => ['label' => 'LinkedIn page', 'platforms' => [Platform::ZernioLinkedInPage, Platform::LinkedInPage]],
        'x' => ['label' => 'X', 'platforms' => [Platform::X]],
        'facebook' => ['label' => 'Facebook', 'platforms' => [Platform::Facebook]],
        'instagram' => ['label' => 'Instagram', 'platforms' => [Platform::Instagram, Platform::InstagramFacebook]],
        'youtube' => ['label' => 'YouTube', 'platforms' => [Platform::YouTube]],
        'threads' => ['label' => 'Threads', 'platforms' => [Platform::Threads]],
        'tiktok' => ['label' => 'TikTok', 'platforms' => [Platform::TikTok]],
        'pinterest' => ['label' => 'Pinterest', 'platforms' => [Platform::Pinterest]],
        'bluesky' => ['label' => 'Bluesky', 'platforms' => [Platform::Bluesky]],
        'mastodon' => ['label' => 'Mastodon', 'platforms' => [Platform::Mastodon]],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    public static function label(string $network): string
    {
        return self::MAP[$network]['label'] ?? ucfirst($network);
    }

    /** X goes out by hand (PRD 12), so it needs no connected channel to be reviewed. */
    public static function byHand(string $network): bool
    {
        return $network === 'x';
    }

    /** @return list<string> */
    public static function platformValues(string $network): array
    {
        return array_map(fn (Platform $p): string => $p->value, self::MAP[$network]['platforms'] ?? []);
    }

    public static function networkOf(Platform $platform): ?string
    {
        foreach (self::MAP as $key => $network) {
            if (in_array($platform, $network['platforms'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The workspace's channels for one network, oldest first.
     *
     * @return Collection<int, SocialAccount>
     */
    public static function channels(Workspace|string $workspace, string $network): Collection
    {
        $values = self::platformValues($network);

        return $values === []
            ? new Collection
            : SocialAccount::query()
                ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
                ->whereIn('platform', $values)
                ->orderBy('created_at')
                ->get();
    }

    /**
     * Which networks have a channel in the workspace, by network key.
     *
     * @return array<string, true>
     */
    public static function connected(string $workspaceId): array
    {
        return SocialAccount::query()->where('workspace_id', $workspaceId)->pluck('platform')
            ->map(fn ($p): ?string => self::networkOf($p instanceof Platform ? $p : Platform::from((string) $p)))
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $n): array => [$n => true])
            ->all();
    }

    /**
     * The draft's post type on that channel, from the preview's media and the format.
     *
     * @param  array<string, mixed>  $preview
     */
    public static function contentType(SocialAccount $channel, array $preview, ?string $format): ContentType
    {
        $media = (string) data_get($preview, 'media.type', '');
        $video = $media === 'video';
        $format = strtolower((string) $format);

        return match ($channel->platform) {
            Platform::Instagram, Platform::InstagramFacebook => $video ? ContentType::InstagramReel : ContentType::InstagramFeed,
            Platform::Facebook => $video && str_contains($format, 'reel') ? ContentType::FacebookReel : ContentType::FacebookPost,
            Platform::TikTok => in_array($media, ['image', 'images', 'carousel'], true) ? ContentType::TikTokPhoto : ContentType::TikTokVideo,
            Platform::YouTube => str_contains($format, 'short') || (bool) data_get($preview, 'media.tall') || ! str_contains($format, 'video')
                ? ContentType::YouTubeShort
                : ContentType::YouTubeVideo,
            Platform::Pinterest => match ($media) {
                'video' => ContentType::PinterestVideoPin,
                'carousel', 'images' => ContentType::PinterestCarousel,
                default => ContentType::PinterestPin,
            },
            default => ContentType::defaultFor($channel->platform),
        };
    }
}
