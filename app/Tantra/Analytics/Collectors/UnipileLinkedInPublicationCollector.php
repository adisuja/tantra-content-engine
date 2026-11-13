<?php

declare(strict_types=1);

namespace App\Tantra\Analytics\Collectors;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractPublicationHistoryCollector;
use Carbon\CarbonImmutable;

/**
 * tantra: a LinkedIn profile's own posts (OAuth or Unipile channel) from
 * Unipile's users/me/posts, reposts of other people's posts left out. A post the
 * Engine published is stored under its platform_post_id, so it links to its post.
 */
class UnipileLinkedInPublicationCollector extends AbstractPublicationHistoryCollector
{
    public function __construct(private readonly UnipileLinkedInReader $reader) {}

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $accountId = $this->reader->accountFor($account);
        $list = $this->reader->page($accountId, $cursor);
        $publications = [];
        $crossedCutoff = false;

        foreach ($list['items'] as $item) {
            $publishedAt = UnipileLinkedInReader::publishedAt($item);

            if (! $publishedAt) {
                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            if (($item['is_repost'] ?? false) === true) {
                continue;
            }

            $providerPostId = $this->reader->providerId($account, $accountId, $item);

            if ($providerPostId === null) {
                continue;
            }

            $attachments = array_values(array_filter((array) ($item['attachments'] ?? []), 'is_array'));
            $thumbnail = collect($attachments)->map(fn (array $a): mixed => $a['url'] ?? $a['thumbnail'] ?? null)->first(fn (mixed $url): bool => is_string($url) && $url !== '');

            $publications[] = new DiscoveredPublication(
                providerPostId: $providerPostId,
                publishedAt: $publishedAt,
                contentType: $this->contentType($attachments),
                providerContentType: is_string($attachments[0]['type'] ?? null) ? $attachments[0]['type'] : null,
                permalink: is_string($item['share_url'] ?? null) ? $item['share_url'] : null,
                excerpt: is_string($item['text'] ?? null) ? $item['text'] : null,
                previewMetadata: $thumbnail ? ['thumbnail_url' => $thumbnail] : null,
                providerMetadata: ['unipile_post_id' => $item['id'] ?? null, ...UnipileLinkedInReader::counters($item)],
            );
        }

        $hasNext = $list['next'] !== null && ! $crossedCutoff;

        return new PublicationPage($publications, $hasNext ? $list['next'] : null, ! $hasNext, providerRowCount: count($list['items']));
    }

    /** @param list<array<string, mixed>> $attachments */
    private function contentType(array $attachments): PublicationContentType
    {
        $types = array_map(fn (array $a): string => strtolower((string) ($a['type'] ?? '')), $attachments);

        return match (true) {
            $attachments === [] => PublicationContentType::Text,
            in_array('poll', $types, true) => PublicationContentType::Poll,
            in_array('video', $types, true) => PublicationContentType::Video,
            in_array('document', $types, true), in_array('file', $types, true), count($attachments) > 1 => PublicationContentType::Carousel,
            in_array('img', $types, true), in_array('image', $types, true) => PublicationContentType::Image,
            in_array('linkedin_post', $types, true), in_array('link', $types, true) => PublicationContentType::Link,
            default => PublicationContentType::Unknown,
        };
    }
}
