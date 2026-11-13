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
 * tantra: one Tantra page post's LinkedIn stats through Zernio, by URN:
 * impressions, reach, reactions, comments, reposts (as shares) and clicks.
 */
class ZernioPageMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function __construct(private readonly ZernioPageReader $reader) {}

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $channel = $this->account($publication);
        $accountId = $this->reader->accountFor($channel);
        $urn = $this->reader->urnOf((string) $publication->remote_id, $publication->permalink);

        if ($urn === null) {
            throw AnalyticsCollectionException::malformed('Zernio gives no LinkedIn link for this post yet.');
        }

        $values = $this->reader->postAnalytics($accountId, $urn);
        $interactions = array_filter([$values['likes'] ?? null, $values['comments'] ?? null, $values['shares'] ?? null], 'is_numeric');

        // Engagements as Tantra counts them everywhere: reactions, comments and reposts, not clicks.
        return $this->observation($date, $this->present([
            $this->count(MetricKey::Impressions, $values, 'impressions'),
            $this->count(MetricKey::Reach, $values, 'reach'),
            $this->count(MetricKey::Reactions, $values, 'likes'),
            $this->count(MetricKey::Comments, $values, 'comments'),
            $this->count(MetricKey::Shares, $values, 'shares'),
            $this->count(MetricKey::Clicks, $values, 'clicks'),
            $interactions === [] ? null : $this->count(MetricKey::Engagements, ['engagements' => array_sum(array_map('intval', $interactions))], 'engagements'),
        ]));
    }
}
