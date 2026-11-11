<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Models\Post;
use App\Tantra\Unipile\UnipileClient;

/** tantra: switches and helpers for team engagement (PRD 10.5). */
final class Boost
{
    public const QUEUE = 'tantra-boost';

    public static function enabled(): bool
    {
        return (bool) config('tantra.boost.enabled') && UnipileClient::configured();
    }

    /** The LinkedIn native id of a published post (urn:li:share:N, ugcPost:N or activity:N), from its id or URL. */
    public static function nativeRef(Post $post): ?string
    {
        foreach ([(string) $post->platform_post_id, (string) $post->platform_url] as $candidate) {
            if (preg_match('/urn:li:(share|ugcPost|activity):\d+/', $candidate, $m)) {
                return $m[0];
            }
            if (preg_match('/(ugcPost|activity|share)[:-](\d{10,})/', $candidate, $m)) {
                return "urn:li:{$m[1]}:{$m[2]}";
            }
        }

        return null;
    }
}
