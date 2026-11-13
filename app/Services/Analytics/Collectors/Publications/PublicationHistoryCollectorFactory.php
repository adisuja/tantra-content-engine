<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Tantra\Analytics\Collectors\UnipileLinkedInPublicationCollector;
use App\Tantra\Analytics\Collectors\ZernioPagePublicationCollector;

class PublicationHistoryCollectorFactory
{
    public function supports(Platform $platform): bool
    {
        return $platform->isIncludedInAnalytics();
    }

    public function for(SocialAccount $account): AbstractPublicationHistoryCollector
    {
        return match ($account->platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramPublicationCollector::class),
            Platform::Facebook => app(FacebookPublicationCollector::class),
            Platform::Threads => app(ThreadsPublicationCollector::class),
            Platform::X => app(XPublicationCollector::class),
            Platform::Pinterest => app(PinterestPublicationCollector::class),
            Platform::YouTube => app(YouTubePublicationCollector::class),
            Platform::TikTok => app(TikTokPublicationCollector::class),
            Platform::Bluesky => app(BlueskyPublicationCollector::class),
            Platform::Mastodon => app(MastodonPublicationCollector::class),
            // tantra: LinkedIn profiles through Unipile, the Tantra page through Zernio (PRD 25.7).
            Platform::LinkedIn, Platform::UnipileLinkedIn => app(UnipileLinkedInPublicationCollector::class),
            Platform::ZernioLinkedInPage => app(ZernioPagePublicationCollector::class),
            default => throw AnalyticsCollectionException::unsupported("{$account->platform->value} publication history is not supported"),
        };
    }
}
