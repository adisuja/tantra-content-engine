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
 * tantra: the Tantra page's posts made through Zernio, stored under Zernio's
 * post id, which is what ZernioPublisher keeps as the post's platform_post_id,
 * so the Engine's own page posts link to their numbers.
 */
class ZernioPagePublicationCollector extends AbstractPublicationHistoryCollector
{
    public const int PAGE_SIZE = 50;

    public function __construct(private readonly ZernioPageReader $reader) {}

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $accountId = $this->reader->accountFor($account);
        $pageNumber = max(1, (int) ($cursor ?? 1));
        $response = $this->reader->posts($pageNumber, self::PAGE_SIZE);
        $posts = array_values(array_filter((array) $response->json('posts'), 'is_array'));
        $publications = [];
        $crossedCutoff = false;

        foreach ($posts as $post) {
            $target = collect((array) ($post['platforms'] ?? []))->first(fn (mixed $target): bool => is_array($target)
                && (string) (data_get($target, 'accountId._id') ?? data_get($target, 'accountId.id') ?? (is_string($target['accountId'] ?? null) ? $target['accountId'] : '')) === $accountId);
            $id = (string) ($post['_id'] ?? $post['id'] ?? '');

            if (! is_array($target) || $id === '') {
                continue;
            }

            $publishedAt = $this->publishedAt($target['publishedAt'] ?? $post['publishedAt'] ?? $post['scheduledFor'] ?? null);

            if (! $publishedAt) {
                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $media = array_values(array_filter((array) ($post['mediaItems'] ?? []), 'is_array'));

            $publications[] = new DiscoveredPublication(
                providerPostId: $id,
                publishedAt: $publishedAt,
                contentType: $this->contentType($media),
                providerContentType: is_string($media[0]['type'] ?? null) ? $media[0]['type'] : null,
                permalink: is_string($target['platformPostUrl'] ?? null) ? $target['platformPostUrl'] : null,
                excerpt: is_string($post['content'] ?? null) ? $post['content'] : null,
                previewMetadata: is_string($media[0]['url'] ?? null) && ($media[0]['type'] ?? null) === 'image' ? ['thumbnail_url' => $media[0]['url']] : null,
            );
        }

        $pages = (int) $response->json('pagination.pages', $pageNumber);
        $hasNext = ! $crossedCutoff && $posts !== [] && $pageNumber < $pages;

        return new PublicationPage($publications, $hasNext ? (string) ($pageNumber + 1) : null, ! $hasNext, providerRowCount: count($posts));
    }

    /** @param list<array<string, mixed>> $media */
    private function contentType(array $media): PublicationContentType
    {
        $types = array_map(fn (array $item): string => strtolower((string) ($item['type'] ?? '')), $media);

        return match (true) {
            $media === [] => PublicationContentType::Text,
            in_array('video', $types, true) => PublicationContentType::Video,
            in_array('document', $types, true), count($media) > 1 => PublicationContentType::Carousel,
            default => PublicationContentType::Image,
        };
    }
}
