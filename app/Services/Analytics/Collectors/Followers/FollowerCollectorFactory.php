<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Tantra\Analytics\Collectors\UnipileLinkedInFollowerCollector;
use App\Tantra\Analytics\Collectors\ZernioPageFollowerCollector;

class FollowerCollectorFactory
{
    public function supports(Platform $platform): bool
    {
        return $platform->isIncludedInAnalytics();
    }

    public function for(Platform $platform): AbstractFollowerCollector
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramFollowerCollector::class),
            Platform::Facebook => app(FacebookFollowerCollector::class),
            Platform::Threads => app(ThreadsFollowerCollector::class),
            Platform::X => app(XFollowerCollector::class),
            Platform::Pinterest => app(PinterestFollowerCollector::class),
            Platform::YouTube => app(YouTubeFollowerCollector::class),
            Platform::TikTok => app(TikTokFollowerCollector::class),
            Platform::Bluesky => app(BlueskyFollowerCollector::class),
            Platform::Mastodon => app(MastodonFollowerCollector::class),
            // tantra: LinkedIn profiles through Unipile, the Tantra page through Zernio (PRD 25.7).
            Platform::LinkedIn, Platform::UnipileLinkedIn => app(UnipileLinkedInFollowerCollector::class),
            Platform::ZernioLinkedInPage => app(ZernioPageFollowerCollector::class),
            default => throw AnalyticsCollectionException::unsupported("{$platform->value} follower analytics is excluded"),
        };
    }
}
