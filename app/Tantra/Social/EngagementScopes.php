<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Enums\SocialAccount\Platform;

/**
 * tantra: the scopes first comments and Threads replies need. Meta refuses a
 * login that asks for a permission its app does not have yet, so they are
 * asked for only once `tantra.meta.engagement_scopes` is on, or once the network
 * is listed in `tantra.meta.engagement_networks` (one network at a time, while
 * the others still lack the permission in the Meta app). They are added at
 * connect only, never to the requested list a callback falls back to, so a
 * network that reports no scopes never has these recorded as granted.
 */
final class EngagementScopes
{
    /** @var array<string, list<string>> */
    private const array SCOPES = [
        'instagram' => ['instagram_business_manage_comments'],
        'instagram-facebook' => ['instagram_manage_comments'],
        'facebook' => ['pages_manage_engagement'],
        'threads' => ['threads_read_replies', 'threads_manage_replies'],
    ];

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    public static function with(Platform $platform, array $scopes): array
    {
        $extra = self::SCOPES[$platform->value] ?? [];

        if ($extra === [] || ! self::enabled($platform)) {
            return $scopes;
        }

        return array_values(array_unique([...$scopes, ...$extra]));
    }

    /** @return list<string> */
    public static function for(Platform $platform): array
    {
        return self::SCOPES[$platform->value] ?? [];
    }

    public static function enabled(Platform $platform): bool
    {
        return (bool) config('tantra.meta.engagement_scopes', false)
            || in_array($platform->value, (array) config('tantra.meta.engagement_networks', []), true);
    }
}
