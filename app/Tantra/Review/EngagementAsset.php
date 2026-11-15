<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Arr;

/**
 * tantra: one post's giveaway as the Tantra CRM's Engagement asset library reads it. The shape is a Content Studio
 * posts.json record, the contract of the CRM's `assetFromStudio` (tantra-crm crm-worker/src/engagement/assets.ts,
 * POST /api/engagement/assets/import): id, profile, resource {name, keyword, what, url, pages, files},
 * dm {message, connect, email_subject, email_body}, first_comment, published.url. The dm block carries the review
 * owner's edited messages (Messages::for), so the CRM's defaults are what the Engine shows. Placeholders stay in the
 * Studio format ({first_name}, [POST LINK]); the CRM converts them.
 */
final class EngagementAsset
{
    /** Engine channel => the Studio dm key the CRM reads. */
    public const DM_KEYS = [
        'linkedin_dm' => 'message',
        'linkedin_connect' => 'connect',
        'email_subject' => 'email_subject',
        'email_body' => 'email_body',
        'whatsapp' => 'whatsapp',
        'sms' => 'sms',
    ];

    /**
     * The record, or null when the post promises no resource (the CRM skips those).
     *
     * @return array<string, mixed>|null
     */
    public static function record(ReviewItem $item, ?string $postUrl = null): ?array
    {
        $payload = (array) $item->payload;
        $resource = Arr::only((array) ($payload['resource'] ?? []), ['name', 'keyword', 'what', 'url', 'pages', 'files']);
        if (trim((string) ($resource['name'] ?? '')) === '') {
            return null;
        }

        $dm = [];
        foreach (Messages::for($item) as $channel => $m) {
            if (isset(self::DM_KEYS[$channel]) && trim($m['text']) !== '') {
                $dm[self::DM_KEYS[$channel]] = $m['text'];
            }
        }
        $postUrl ??= $item->posts()->pluck('platform_url')->filter()->first();

        return array_filter([
            'id' => $item->engine_post_id,
            'profile' => $payload['profile'] ?? null,
            'resource' => array_filter($resource, fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []),
            'dm' => $dm,
            'first_comment' => $payload['first_comment'] ?? null,
            'published' => filled($postUrl) ? ['url' => (string) $postUrl] : null,
        ], fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
