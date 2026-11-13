<?php

declare(strict_types=1);

namespace App\Tantra\Analytics\Collectors;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Services\Analytics\Collectors\Metrics\AbstractPublicationMetricsCollector;
use Carbon\CarbonImmutable;

/**
 * tantra: one LinkedIn post's numbers (OAuth or Unipile channel) from the
 * account's post list, read once an hour for every post of the account:
 * impressions when Unipile has them, reactions (every kind summed), comments
 * and reposts (stored as shares, as upstream does for X and Threads reposts).
 */
class UnipileLinkedInMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function __construct(private readonly UnipileLinkedInReader $reader) {}

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $channel = $this->account($publication);
        $accountId = $this->reader->accountFor($channel);
        $item = $this->reader->find($channel, $accountId, (string) $publication->remote_id, $publication->provider_published_at);

        if ($item === null) {
            // Not in the recent list: too old to page back to, or hidden. Never read as deleted.
            throw AnalyticsCollectionException::malformed('LinkedIn post is not in the account\'s recent posts.');
        }

        $counters = UnipileLinkedInReader::counters($item);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Impressions, $counters, 'impressions'),
            $this->count(MetricKey::Reactions, $counters, 'reactions'),
            $this->count(MetricKey::Comments, $counters, 'comments'),
            $this->count(MetricKey::Shares, $counters, 'reposts'),
        ])));
    }
}
