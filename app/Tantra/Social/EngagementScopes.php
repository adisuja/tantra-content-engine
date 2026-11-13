<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Enums\SocialAccount\Platform;

/**
 * tantra: the scopes first comments and Threads replies need. Meta refuses a
 * login that asks for a permission its app does not have yet, so they are
 * asked for only once `tantra.meta.engagement_scopes` is on. They are added at
 * connect only, never to the requested list a callback falls back to, so a
 * network that reports no scopes never has these recorded as granted.
 */
final class EngagementScopes
{
    /** @var array<string, string> */
    private const array SCOPES = [
        'instagram' => 'instagram_business_manage_comments',
        'instagram-facebook' => 'instagram_manage_comments',
        'facebook' => 'pages_manage_engagement',
        'threads' => 'threads_manage_replies',
    ];

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    public static function with(Platform $platform, array $scopes): array
    {
        $extra = self::SCOPES[$platform->value] ?? null;

        if ($extra === null || ! config('tantra.meta.engagement_scopes', false)) {
            return $scopes;
        }

        return array_values(array_unique([...$scopes, $extra]));
    }

    public static function for(Platform $platform): ?string
    {
        return self::SCOPES[$platform->value] ?? null;
    }
}
