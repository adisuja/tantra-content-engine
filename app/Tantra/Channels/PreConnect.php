<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Models\User;

/**
 * tantra: the short step before a network's sign in while its developer app
 * is still in development (only testers the owner invited can sign in) or
 * has no keys yet. Every connect entry point reaches it the same way: the
 * PreConnectGate middleware sends the connect route here, so the connect
 * dialog, the channel list's Reconnect, onboarding links and emails all show
 * the same step.
 */
final class PreConnect
{
    /** The query flag "Continue" adds, so the gate lets the sign in start. */
    public const string CONTINUE_FLAG = 'prepared';

    /** Who runs each developer app, as the step names them. */
    private const array PROVIDERS = [
        'linkedin' => 'LinkedIn',
        'linkedin_page' => 'LinkedIn',
        'meta_facebook' => 'Meta',
        'meta_instagram' => 'Meta',
        'meta_threads' => 'Meta',
        'x' => 'X',
        'google_youtube' => 'Google',
        'google_business' => 'Google',
        'tiktok' => 'TikTok',
        'pinterest' => 'Pinterest',
        'discord' => 'Discord',
        'telegram' => 'Telegram',
    ];

    /**
     * The network a connect route starts: `app.social.instagram-facebook.connect`
     * is Instagram via Facebook. Null for anything else.
     */
    public static function networkFromRoute(?string $name): ?Platform
    {
        if ($name === null || ! preg_match('/^app\.social\.([a-z-]+)\.connect$/', $name, $m)) {
            return null;
        }

        return Platform::tryFrom($m[1]) ?? Platform::tryFrom(str_replace('-', '_', $m[1]));
    }

    /** The URL segment of a network's connect route (google_business is google-business). */
    public static function slug(Platform $platform): string
    {
        return str_replace('_', '-', $platform->value);
    }

    public static function prepareUrl(Platform $platform): string
    {
        return route('tantra.connect.prepare', self::slug($platform));
    }

    public static function settingsUrl(string $app): string
    {
        // tantra: developer keys live on the owner's Developer apps page.
        return url('/settings/developer-apps?channel='.$app.'#channels');
    }

    /** Whether the network's card connects now (its keys are in). */
    public static function ready(Platform $platform): bool
    {
        return $platform === Platform::LinkedIn ? $platform->isReadyToConnect() : $platform->isEnabled();
    }

    /** Whether a person starting this network's sign in sees the step first. */
    public static function needed(Platform $platform): bool
    {
        if (! ChannelApps::enforced() || ChannelApps::appFor($platform->value) === null) {
            return false;
        }

        return ! self::ready($platform) || ! ChannelApps::isLive($platform->value);
    }

    /**
     * What the step shows. `state` is "setup" (no keys yet) or "testing"
     * (keys in, app not live yet).
     *
     * @param  array<string, mixed>  $query  the connect route's query, kept for Continue
     * @return array<string, mixed>
     */
    public static function step(Platform $platform, ?User $user, array $query): array
    {
        $app = (string) ChannelApps::appFor($platform->value);
        $entry = ChannelApps::catalog()[$app];
        $network = ConnectFailures::networkName($platform);
        $manager = ChannelApps::manager();
        $canManage = ChannelApps::canManage($user);
        $state = self::ready($platform) ? 'testing' : 'setup';
        $owner = $manager !== null && $manager->id !== $user?->id
            ? (string) strtok((string) $manager->name, ' ')
            : __('tantra.connect.prepare.owner_fallback');

        $continue = $query;
        unset($continue[self::CONTINUE_FLAG]);

        return [
            'state' => $state,
            'platform' => $platform->value,
            'network' => $network,
            'app' => $app,
            'app_label' => $entry['label'],
            'provider' => self::PROVIDERS[$app] ?? $network,
            'owner_steps' => __("tantra.connect.testing.apps.{$app}.owner"),
            'your_steps' => __("tantra.connect.testing.apps.{$app}.you"),
            'accept_url' => $entry['testing']['accept'] ?? null,
            // Developer detail (the network's console and catch) only for the owner.
            'console_url' => $canManage ? ($entry['testing']['console'] ?? $entry['portal']) : null,
            'catch' => $canManage ? $entry['catch'] : null,
            'continue_url' => $state === 'testing'
                ? route('app.social.'.self::slug($platform).'.connect', [...$continue, self::CONTINUE_FLAG => '1'])
                : null,
            'back_url' => self::backUrl($query['return_to'] ?? null),
            'request_url' => route('tantra.connect.request-access', self::slug($platform)),
            'can_manage' => $canManage,
            'settings_url' => self::settingsUrl($app),
            'owner_name' => $owner,
        ];
    }

    /** Only a path inside the app, never another site. */
    public static function backUrl(mixed $returnTo): string
    {
        return is_string($returnTo) && str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//') && ! str_contains($returnTo, '\\')
            ? url($returnTo)
            : route('app.workspace.channels');
    }
}
