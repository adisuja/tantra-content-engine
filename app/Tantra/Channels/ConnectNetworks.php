<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * tantra: the tiles of the "Connect a channel" grid (Adi 2026-11-14): one per
 * network a person adds, whether it connects now, and what is already
 * connected in the current workspace. Nothing about developer apps reaches
 * this list except, for the instance owner, the key of the app to set up.
 */
final class ConnectNetworks
{
    /**
     * Tile order, and the account platforms each tile counts as connected.
     *
     * @var array<string, list<string>>
     */
    private const TILES = [
        'linkedin' => ['linkedin', 'unipile-linkedin'],
        'linkedin-page' => ['linkedin-page', 'zernio-linkedin-page'],
        'x' => ['x'],
        'facebook' => ['facebook'],
        'instagram' => ['instagram', 'instagram-facebook'],
        'threads' => ['threads'],
        'youtube' => ['youtube'],
        'tiktok' => ['tiktok'],
        'pinterest' => ['pinterest'],
        'bluesky' => ['bluesky'],
        'mastodon' => ['mastodon'],
        'telegram' => ['telegram'],
        'discord' => ['discord'],
        'google_business' => ['google_business'],
    ];

    /** Plain names where the network's own label says more than the logo needs. */
    private const LABELS = [
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'google_business' => 'Google Business',
    ];

    /** How each network connects: a sign-in redirect or a short form. */
    private const KINDS = [
        'bluesky' => 'credentials',
        'mastodon' => 'server',
        'telegram' => 'bot',
    ];

    /**
     * @return list<array{value: string, label: string, kind: string, ready: bool, methods: list<string>, connected: bool, count: int, avatar_url: string|null, setup_app: string|null}>
     */
    public static function for(?User $user): array
    {
        $workspace = $user?->currentWorkspace;
        $owner = ChannelApps::canManage($user);

        $accounts = $workspace === null ? collect() : SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'platform', 'status', 'avatar_url', 'workspace_id']);

        $tiles = [];
        foreach (self::TILES as $value => $platforms) {
            $platform = Platform::from($value);
            $live = $accounts->filter(fn (SocialAccount $a): bool => in_array($a->platform->value, $platforms, true)
                && ! in_array($a->status, [Status::Disconnected, Status::TokenExpired], true));
            $ready = self::ready($platform);

            $tiles[] = [
                'value' => $value,
                'label' => self::LABELS[$value] ?? $platform->label(),
                'kind' => self::KINDS[$value] ?? 'oauth',
                'ready' => $ready,
                // Instagram has two sign-in routes; only the ready ones are offered.
                'methods' => $platform === Platform::Instagram ? Platform::instagramConnectMethods() : [],
                'connected' => $live->isNotEmpty(),
                'count' => $live->count(),
                'avatar_url' => $live->first()?->avatar_url,
                // Only the instance owner gets a "Set up" link, to the admin page.
                'setup_app' => $owner && ! $ready ? ChannelApps::appFor($value === 'instagram' ? 'instagram-facebook' : $value) : null,
            ];
        }

        return $tiles;
    }

    private static function ready(Platform $platform): bool
    {
        return match ($platform) {
            // The LinkedIn tile is the personal profile; company pages have their own tile.
            Platform::LinkedIn, Platform::LinkedInPage => $platform->isEnabled(),
            default => $platform->isReadyToConnect(),
        };
    }
}
