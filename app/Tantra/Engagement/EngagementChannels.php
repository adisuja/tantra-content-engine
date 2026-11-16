<?php

declare(strict_types=1);

namespace App\Tantra\Engagement;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use App\Tantra\Provision\TantraWorkspaceLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * tantra: the connected accounts the Tantra CRM's Engagement adapters use (tantra-crm
 * docs/crm/ENGAGEMENT_CHANNELS_CONTRACT.md, with the CRM review's rules): a CRM workspace id maps explicitly to Engine
 * workspaces (ENGINE_CHANNELS_WORKSPACES), an unmapped one gets nothing, tokens go out only for a read or a send and
 * are refreshed first, and no token is ever logged.
 */
final class EngagementChannels
{
    /** Engine platform => CRM platform. */
    public const PLATFORMS = [
        'bluesky' => 'bluesky',
        'mastodon' => 'mastodon',
        'x' => 'x',
        'facebook' => 'facebook',
        'instagram-facebook' => 'instagram',
        'instagram' => 'instagram',
        'threads' => 'threads',
        'youtube' => 'youtube',
        'telegram' => 'telegram',
        'discord' => 'discord',
    ];

    /** Purposes that may carry tokens. */
    public const TOKEN_PURPOSES = ['read', 'send'];

    /**
     * CRM workspace id => Engine workspace ids, from "ws_abc=uuid1|uuid2,ws_def=uuid3".
     *
     * @return array<string, list<string>>
     */
    public static function workspaceMap(): array
    {
        $map = [];
        foreach (explode(',', (string) config('tantra.engagement_channels.workspaces', '')) as $entry) {
            [$crm, $engine] = array_pad(explode('=', $entry, 2), 2, '');
            $crm = trim($crm);
            $ids = array_values(array_filter(array_map('trim', explode('|', $engine)), fn (string $id): bool => $id !== ''));
            if ($crm !== '' && $ids !== []) {
                $map[$crm] = array_values(array_unique([...($map[$crm] ?? []), ...$ids]));
            }
        }

        return $map;
    }

    /** @return list<string> */
    public static function engineWorkspaces(?string $crmWorkspace): array
    {
        if ($crmWorkspace === null) {
            return [];
        }
        // PV-02: the link the Tantra app wrote (POST /api/tantra/provision) first, the env map only as a fallback.
        $linked = TantraWorkspaceLink::query()->where('tantra_workspace_id', $crmWorkspace)->value('workspace_id');

        return $linked !== null ? [(string) $linked] : (self::workspaceMap()[$crmWorkspace] ?? []);
    }

    /** @return list<string> Engine platform values for one CRM platform, or every supported one. */
    public static function enginePlatforms(?string $crmPlatform): array
    {
        return array_keys(array_filter(self::PLATFORMS, fn (string $crm): bool => $crmPlatform === null || $crm === $crmPlatform));
    }

    /**
     * @param  list<string>  $workspaceIds
     * @return list<array<string, mixed>>
     */
    public static function accounts(array $workspaceIds, ?string $crmPlatform, bool $withTokens): array
    {
        if ($workspaceIds === []) {
            return [];
        }

        /** @var Collection<int, SocialAccount> $accounts */
        $accounts = SocialAccount::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('platform', self::enginePlatforms($crmPlatform))
            ->orderBy('created_at')
            ->get();

        return $accounts->map(fn (SocialAccount $a): array => self::shape($a, $withTokens))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function shape(SocialAccount $account, bool $withTokens): array
    {
        if ($withTokens && $account->status === Status::Connected) {
            self::freshen($account);
        }
        $connected = $account->status === Status::Connected;
        $platform = $account->platform;

        $out = [
            'id' => $account->id,
            'platform' => self::PLATFORMS[$platform->value],
            'engine_workspace_id' => $account->workspace_id,
            'user_id' => self::userId($account),
            'username' => $account->username,
            'display_name' => $account->display_name,
            'status' => $connected ? 'connected' : 'disconnected',
            'meta' => self::meta($account, $withTokens && $connected),
        ];
        if ($withTokens) {
            $token = $connected ? (string) $account->access_token : '';
            $out['access_token'] = $token !== '' ? $token : null;
            $out['token_expires_at'] = $connected ? $account->token_expires_at?->toIso8601ZuluString() : null;
        }

        return $out;
    }

    private static function userId(SocialAccount $account): ?string
    {
        $id = $account->platform === Platform::Instagram
            ? (data_get($account->meta, 'ig_user_id') ?? $account->platform_user_id)
            : $account->platform_user_id;

        return $id === null ? null : (string) $id;
    }

    /** @return array<string, mixed> */
    private static function meta(SocialAccount $account, bool $secrets): array
    {
        $m = (array) ($account->meta ?? []);

        $meta = match ($account->platform) {
            Platform::Bluesky => ['service' => $account->bluesky_service],
            Platform::Mastodon => ['instance' => parse_url((string) $account->mastodon_instance, PHP_URL_HOST) ?: (string) $account->mastodon_instance],
            Platform::Facebook, Platform::InstagramFacebook => ['page_id' => isset($m['page_id']) ? (string) $m['page_id'] : null],
            Platform::Instagram => ['ig_user_id' => isset($m['ig_user_id']) ? (string) $m['ig_user_id'] : null],
            Platform::YouTube => ['channel_id' => (string) ($m['channel_id'] ?? $account->platform_user_id)],
            Platform::Telegram => [
                'chat_id' => (string) ($m['chat_id'] ?? $account->platform_user_id),
                'bot_username' => config('trypost.platforms.telegram.bot_username'),
            ] + ($secrets ? ['bot_token' => config('trypost.platforms.telegram.bot_token')] : []),
            // The guild the bot was added to, so the CRM can refuse channels of any other guild.
            Platform::Discord => ['guild_id' => (string) $account->platform_user_id]
                + ($secrets ? ['bot_token' => config('trypost.platforms.discord.bot_token')] : []),
            default => [],
        };

        return array_filter($meta, fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * Refresh a token before it is handed out when the Engine itself would (SocialAccount::needsProactiveTokenRefresh:
     * expired, or expiring soon on platforms that extend the token), so a single use refresh token is never rotated
     * early. The CRM never refreshes, so the stored refresh token is never rotated behind the Engine's back. A failure is logged with
     * the account id and the error class only.
     */
    private static function freshen(SocialAccount $account): void
    {
        if (! $account->platform->hasTokenRefreshFlow() || ! $account->needsProactiveTokenRefresh()) {
            return;
        }
        try {
            app(ConnectionVerifier::class)->refreshToken($account);
        } catch (Throwable $e) {
            Log::warning('Engagement channels: token refresh failed', ['account_id' => $account->id, 'platform' => $account->platform->value, 'error' => $e::class]);
        }
        $account->refresh();
        if ($account->is_token_expired) {
            // Never hand out a dead token.
            $account->setAttribute('status', Status::TokenExpired);
        }
    }
}
