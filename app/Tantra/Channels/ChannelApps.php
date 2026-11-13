<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * tantra: which networks can be connected, and the developer app keys behind
 * them (PRD prd-engine-all-channels.md, D1 to D3).
 *
 * Upstream TryPost hides a network whose `*_ENABLED` flag is off, and our M0
 * deploy switched every network but LinkedIn off because none had keys. Now
 * every network is listed; a network is enabled exactly when its developer
 * app keys are present, either in the server .env or saved by the Account
 * owner in Settings > Connectors > Channels. Saved keys override the .env.
 *
 * Keeping "enabled" equal to "ready" means upstream's own checks (connect
 * guard, LinkedIn scope choice, Instagram methods) keep working unchanged.
 */
final class ChannelApps
{
    private const CACHE_KEY = 'tantra:channel-apps:v1';

    /**
     * One entry per developer app.
     *
     * fields: key => [label, secret?, switch?, config paths written on boot]
     * networks: platform value => fields that must be filled for it to be ready
     *
     * @return array<string, array{label: string, portal: string, help: string, catch: string|null, fields: array<string, array{label: string, secret?: bool, switch?: bool, generated?: bool, config: list<string>}>, networks: array<string, list<string>>, callbacks: list<string>}>
     */
    public static function catalog(): array
    {
        return [
            'linkedin' => [
                'label' => 'LinkedIn',
                'portal' => 'https://www.linkedin.com/developers/apps',
                'help' => 'Add the "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect" products. Company pages use their own app (LinkedIn: company pages).',
                'catch' => 'Profiles reconnect every 60 days.',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'config' => ['services.linkedin.client_id', 'services.linkedin-openid.client_id']],
                    'client_secret' => ['label' => 'Client secret', 'secret' => true, 'config' => ['services.linkedin.client_secret', 'services.linkedin-openid.client_secret']],
                ],
                'networks' => [
                    Platform::LinkedIn->value => ['client_id', 'client_secret'],
                ],
                'callbacks' => ['accounts/linkedin/callback'],
            ],
            // Company pages need an app whose only product is Community Management.
            'linkedin_page' => [
                'label' => 'LinkedIn: company pages',
                'portal' => 'https://www.linkedin.com/developers/apps',
                'help' => 'Create a second app with only the "Community Management API" product and copy its Client ID and secret. Left empty, pages use the LinkedIn app keys.',
                'catch' => 'LinkedIn must approve Community Management first; the Tantra page posts through Zernio until then. Pages already connected with the LinkedIn app reconnect once after you add these keys.',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'config' => ['services.linkedin-page.client_id']],
                    'client_secret' => ['label' => 'Client secret', 'secret' => true, 'config' => ['services.linkedin-page.client_secret']],
                    'community_management' => ['label' => 'Community Management API approved', 'switch' => true, 'config' => []],
                ],
                'networks' => [
                    Platform::LinkedInPage->value => ['client_id', 'client_secret', 'community_management'],
                ],
                'callbacks' => ['accounts/linkedin/callback'],
            ],
            'meta_facebook' => [
                'label' => 'Meta: Facebook Pages',
                'portal' => 'https://developers.facebook.com/apps',
                'help' => 'Create a Business app, add "Facebook Login for Business", and use its App ID and App secret. Also unlocks Instagram accounts linked to a Facebook Page.',
                'catch' => 'Free. While the app is in development mode only people with a role on it can connect, so add each teammate as a tester.',
                'fields' => [
                    'client_id' => ['label' => 'App ID', 'config' => ['services.facebook.client_id']],
                    'client_secret' => ['label' => 'App secret', 'secret' => true, 'config' => ['services.facebook.client_secret']],
                ],
                'networks' => [
                    Platform::Facebook->value => ['client_id', 'client_secret'],
                    Platform::InstagramFacebook->value => ['client_id', 'client_secret'],
                ],
                'callbacks' => ['accounts/facebook/callback', 'accounts/instagram-facebook/callback'],
            ],
            'meta_instagram' => [
                'label' => 'Meta: Instagram',
                'portal' => 'https://developers.facebook.com/apps',
                'help' => 'In the same Meta app add "Instagram API with Instagram Login" and copy the Instagram app ID and secret shown there (they differ from the Meta App ID).',
                'catch' => 'Newer Meta apps no longer get Instagram Login (Meta shows "not available for newer apps"); for those, connect Instagram through "Instagram via Facebook" (Meta: Facebook Pages keys). Same tester rule while in development mode.',
                'fields' => [
                    'client_id' => ['label' => 'Instagram app ID', 'config' => ['services.instagram.client_id']],
                    'client_secret' => ['label' => 'Instagram app secret', 'secret' => true, 'config' => ['services.instagram.client_secret']],
                ],
                'networks' => [Platform::Instagram->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/instagram/callback'],
            ],
            'meta_threads' => [
                'label' => 'Meta: Threads',
                'portal' => 'https://developers.facebook.com/apps',
                'help' => 'In the same Meta app add the "Threads API" use case and copy the Threads app ID and secret.',
                'catch' => 'Free. Same tester rule as Facebook while in development mode.',
                'fields' => [
                    'client_id' => ['label' => 'Threads app ID', 'config' => ['services.threads.client_id']],
                    'client_secret' => ['label' => 'Threads app secret', 'secret' => true, 'config' => ['services.threads.client_secret']],
                ],
                'networks' => [Platform::Threads->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/threads/callback'],
            ],
            'x' => [
                'label' => 'X',
                'portal' => 'https://developer.x.com/en/portal/dashboard',
                'help' => 'Create a project and app, turn on OAuth 2.0 (Web App, Read and write), and copy the OAuth 2.0 Client ID and secret.',
                'catch' => 'X bills API posting per use, so each post costs a little.',
                'fields' => [
                    'client_id' => ['label' => 'OAuth 2.0 Client ID', 'config' => ['services.x.client_id']],
                    'client_secret' => ['label' => 'OAuth 2.0 Client secret', 'secret' => true, 'config' => ['services.x.client_secret']],
                ],
                'networks' => [Platform::X->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/x/callback'],
            ],
            'google_youtube' => [
                'label' => 'Google: YouTube',
                'portal' => 'https://console.cloud.google.com/apis/credentials',
                'help' => 'Enable "YouTube Data API v3" and "YouTube Analytics API", then create an OAuth client (Web application).',
                'catch' => 'Free. While the OAuth consent screen is in Testing, until Google verifies the app, only the 100 test users you list can connect and they reconnect every 7 days. Since 2 November 2026 uploads from unverified projects are no longer locked to private.',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'config' => ['services.google.client_id']],
                    'client_secret' => ['label' => 'Client secret', 'secret' => true, 'config' => ['services.google.client_secret']],
                ],
                'networks' => [Platform::YouTube->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/youtube/callback'],
            ],
            'google_business' => [
                'label' => 'Google Business Profile',
                'portal' => 'https://console.cloud.google.com/apis/credentials',
                'help' => 'Use a separate OAuth client (Web application) with the Business Profile APIs enabled.',
                'catch' => 'Google must grant Business Profile API access to the project first (a short form).',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'config' => ['services.google-business.client_id']],
                    'client_secret' => ['label' => 'Client secret', 'secret' => true, 'config' => ['services.google-business.client_secret']],
                ],
                'networks' => [Platform::GoogleBusiness->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/google-business/callback'],
            ],
            'tiktok' => [
                'label' => 'TikTok',
                'portal' => 'https://developers.tiktok.com/apps',
                'help' => 'Add "Login Kit" and "Content Posting API" (Direct Post) to the app.',
                'catch' => 'Until TikTok audits the app, posts go out as private (only you can see them).',
                'fields' => [
                    'client_id' => ['label' => 'Client key', 'config' => ['services.tiktok.client_id']],
                    'client_secret' => ['label' => 'Client secret', 'secret' => true, 'config' => ['services.tiktok.client_secret']],
                ],
                'networks' => [Platform::TikTok->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/tiktok/callback'],
            ],
            'pinterest' => [
                'label' => 'Pinterest',
                'portal' => 'https://developers.pinterest.com/apps',
                'help' => 'Create an app and copy its App ID and App secret key.',
                'catch' => 'New apps start on trial access: pins are visible only to you until Pinterest grants standard access.',
                'fields' => [
                    'client_id' => ['label' => 'App ID', 'config' => ['services.pinterest.client_id']],
                    'client_secret' => ['label' => 'App secret key', 'secret' => true, 'config' => ['services.pinterest.client_secret']],
                ],
                'networks' => [Platform::Pinterest->value => ['client_id', 'client_secret']],
                'callbacks' => ['accounts/pinterest/callback'],
            ],
            'discord' => [
                'label' => 'Discord',
                'portal' => 'https://discord.com/developers/applications',
                'help' => 'Create an application, copy the OAuth2 Client ID and secret, then add a Bot and copy its token.',
                'catch' => 'Free.',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'config' => ['services.discord.client_id']],
                    'client_secret' => ['label' => 'Client secret', 'secret' => true, 'config' => ['services.discord.client_secret']],
                    'bot_token' => ['label' => 'Bot token', 'secret' => true, 'config' => ['trypost.platforms.discord.bot_token']],
                ],
                'networks' => [Platform::Discord->value => ['client_id', 'client_secret', 'bot_token']],
                'callbacks' => ['accounts/discord/callback'],
            ],
            'telegram' => [
                'label' => 'Telegram',
                'portal' => 'https://t.me/BotFather',
                'help' => 'Message @BotFather, send /newbot, and copy the token and the bot username. The webhook is registered when you save.',
                'catch' => 'Free.',
                'fields' => [
                    'bot_token' => ['label' => 'Bot token', 'secret' => true, 'config' => ['trypost.platforms.telegram.bot_token']],
                    'bot_username' => ['label' => 'Bot username (without @)', 'config' => ['trypost.platforms.telegram.bot_username']],
                    'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true, 'generated' => true, 'config' => ['trypost.platforms.telegram.webhook_secret']],
                ],
                'networks' => [Platform::Telegram->value => ['bot_token', 'bot_username']],
                'callbacks' => [],
            ],
        ];
    }

    /** Whether readiness decides each network's enabled flag (off in tests). */
    public static function enforced(): bool
    {
        return (bool) config('tantra.channel_apps.enforce', true);
    }

    /** Networks that need no developer app: always connectable. */
    public const OPEN_NETWORKS = ['bluesky', 'mastodon'];

    /**
     * Read the saved keys and write them over the config, then set every
     * network's enabled flag to its readiness. Runs on every boot; one cache
     * read. Never throws (no table yet during install or before migrations).
     */
    public static function apply(): void
    {
        if (! self::enforced() || self::cachingConfig()) {
            return;
        }

        // Queue workers call this again before each job, so remember the
        // .env values first: a removed key must fall back to them.
        self::snapshotEnv();
        $saved = self::saved();
        if ($saved === null) {
            // Cache or database unreachable: keep what the last apply set.
            return;
        }

        foreach (self::catalog() as $app => $entry) {
            foreach ($entry['fields'] as $field => $spec) {
                if (($spec['switch'] ?? false) || $spec['config'] === []) {
                    continue;
                }
                $value = $saved[$app][$field] ?? null;
                $value = filled($value) ? $value : self::envConfig($spec['config'][0]);
                foreach ($spec['config'] as $path) {
                    config([$path => $value]);
                }
            }
        }

        // Company pages fall back to the LinkedIn app while their own app has no keys.
        if (blank(config('services.linkedin-page.client_id')) || blank(config('services.linkedin-page.client_secret'))) {
            config([
                'services.linkedin-page.client_id' => config('services.linkedin.client_id'),
                'services.linkedin-page.client_secret' => config('services.linkedin.client_secret'),
            ]);
        }

        // Socialite needs a redirect; the .env never set one for most networks.
        $base = rtrim((string) config('app.url'), '/');
        foreach (self::REDIRECTS as $service => $path) {
            if (blank(config("services.{$service}.redirect"))) {
                config(["services.{$service}.redirect" => "{$base}/{$path}"]);
            }
        }

        foreach (self::trackedNetworks() as $network) {
            config(["trypost.platforms.{$network}.enabled" => self::isReady($network, $saved)]);
        }
    }

    /**
     * `config:cache` and `optimize` dump the booted config to disk. Saved keys
     * must never be written there (they would sit in plain text and outlive
     * their removal), so apply() stays out of those commands.
     */
    private static function cachingConfig(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }
        $command = (string) ($_SERVER['argv'][1] ?? '');

        return in_array($command, ['config:cache', 'optimize'], true);
    }

    /**
     * The one person who may change channel keys: the owner of the
     * instance's first Account (the one `tantra:create-owner` made). Keys are
     * instance wide, so the owner of any other Account (including the shell
     * Account an invited teammate gets before accepting) may not.
     */
    public static function canManage(?User $user): bool
    {
        if ($user === null || $user->account?->owner_id !== $user->id) {
            return false;
        }

        return $user->account_id === Account::query()->orderBy('created_at')->orderBy('id')->value('id');
    }

    private const REDIRECTS = [
        'linkedin' => 'accounts/linkedin/callback',
        'linkedin-openid' => 'accounts/linkedin/callback',
        'linkedin-page' => 'accounts/linkedin/callback',
        'x' => 'accounts/x/callback',
        'tiktok' => 'accounts/tiktok/callback',
        'google' => 'accounts/youtube/callback',
        'facebook' => 'accounts/facebook/callback',
        'instagram' => 'accounts/instagram/callback',
        'threads' => 'accounts/threads/callback',
        'pinterest' => 'accounts/pinterest/callback',
        'discord' => 'accounts/discord/callback',
        'google-business' => 'accounts/google-business/callback',
    ];

    /** @return list<string> every network this class decides on */
    public static function trackedNetworks(): array
    {
        $networks = self::OPEN_NETWORKS;
        foreach (self::catalog() as $entry) {
            $networks = [...$networks, ...array_keys($entry['networks'])];
        }

        return $networks;
    }

    /** The app whose keys a network needs, or null for open networks. */
    public static function appFor(string $network): ?string
    {
        foreach (self::catalog() as $app => $entry) {
            if (array_key_exists($network, $entry['networks'])) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $saved
     */
    public static function isReady(string $network, ?array $saved = null): bool
    {
        if (in_array($network, self::OPEN_NETWORKS, true)) {
            return true;
        }

        return self::missing($network, $saved) === [];
    }

    /**
     * Labels of the fields still missing for a network ([] when ready).
     *
     * @param  array<string, array<string, mixed>>|null  $saved
     * @return list<string>
     */
    public static function missing(string $network, ?array $saved = null): array
    {
        $app = self::appFor($network);
        if ($app === null) {
            return [];
        }
        $saved ??= self::saved() ?? [];
        $entry = self::catalog()[$app];
        $missing = [];
        foreach ($entry['networks'][$network] as $field) {
            if (! self::filled($app, $field, $saved)) {
                $missing[] = $entry['fields'][$field]['label'];
            }
        }

        return $missing;
    }

    /**
     * Whether a field has a value, saved in the app or set in the .env.
     *
     * @param  array<string, array<string, mixed>>  $saved
     */
    public static function filled(string $app, string $field, array $saved): bool
    {
        $spec = self::catalog()[$app]['fields'][$field];
        $value = $saved[$app][$field] ?? null;
        if ($spec['switch'] ?? false) {
            // A saved choice wins; otherwise the old .env flag (via config).
            if (array_key_exists($app, $saved) && array_key_exists($field, $saved[$app])) {
                return (bool) $value;
            }

            // The switch lived on the LinkedIn app before pages had their own.
            if ($app === 'linkedin_page' && array_key_exists('community_management', $saved['linkedin'] ?? [])) {
                return (bool) $saved['linkedin']['community_management'];
            }

            return $app === 'linkedin_page' && $field === 'community_management' && (bool) config('tantra.channel_apps.linkedin_page', false);
        }
        if (filled($value)) {
            return true;
        }
        // Company pages use the LinkedIn app's keys while their own are empty.
        if ($app === 'linkedin_page' && ! self::filledOwn($app, $saved)) {
            return self::filled('linkedin', $field, $saved);
        }
        // Set in the .env (config came from it before apply() wrote over it).
        $path = $spec['config'][0] ?? null;

        return $path !== null && filled(self::envConfig($path));
    }

    /**
     * Whether an app has both its own keys (saved or in the .env).
     *
     * @param  array<string, array<string, mixed>>  $saved
     */
    private static function filledOwn(string $app, array $saved): bool
    {
        foreach (['client_id', 'client_secret'] as $field) {
            $path = self::catalog()[$app]['fields'][$field]['config'][0];
            if (blank($saved[$app][$field] ?? null) && blank(self::envConfig($path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where a field's value comes from: "app", "server" or null.
     *
     * @param  array<string, array<string, mixed>>  $saved
     */
    public static function source(string $app, string $field, array $saved): ?string
    {
        if (filled($saved[$app][$field] ?? null)) {
            return 'app';
        }
        $path = self::catalog()[$app]['fields'][$field]['config'][0] ?? null;

        return $path !== null && filled(self::envConfig($path)) ? 'server' : null;
    }

    /**
     * The config value as the .env set it, before any saved key was applied.
     */
    private static function envConfig(string $path): mixed
    {
        if (! array_key_exists($path, self::$env)) {
            self::$env[$path] = config($path);
        }

        return self::$env[$path];
    }

    /** @var array<string, mixed> config paths as the .env set them */
    private static array $env = [];

    /**
     * Remember the .env values before apply() writes saved keys over them,
     * so a removed key falls back to the .env and readiness stays honest.
     */
    /** Drop the snapshot; the next apply() takes it again (tests). */
    public static function forgetEnv(): void
    {
        self::$env = [];
    }

    public static function snapshotEnv(bool $fresh = false): void
    {
        if ($fresh) {
            self::$env = [];
        }
        foreach (self::catalog() as $entry) {
            foreach ($entry['fields'] as $spec) {
                foreach (array_slice($spec['config'], 0, 1) as $path) {
                    self::envConfig($path);
                }
            }
        }
    }

    /**
     * Saved keys, decrypted: app => field => value.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function saved(): ?array
    {
        try {
            // Cached encrypted, for a few minutes, so Redis never holds a
            // plain secret and a restored database is picked up soon.
            $sealed = Cache::remember(self::CACHE_KEY, 300, function (): string {
                $rows = Schema::hasTable('tantra_channel_apps')
                    ? ChannelApp::query()->get()
                        ->mapWithKeys(fn (ChannelApp $row): array => [$row->app => (array) $row->credentials])
                        ->all()
                    : [];

                return Crypt::encrypt($rows);
            });

            return (array) Crypt::decrypt($sealed);
        } catch (Throwable) {
            return null;
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * What the connect dialog shows for a network that is not ready.
     *
     * @return array{app: string, app_label: string, missing: list<string>, portal: string, catch: string|null}|null
     */
    public static function setupFor(string $network): ?array
    {
        if (self::isReady($network)) {
            return null;
        }
        $app = (string) self::appFor($network);
        $entry = self::catalog()[$app];

        return [
            'app' => $app,
            'app_label' => $entry['label'],
            'missing' => self::missing($network),
            'portal' => $entry['portal'],
            'catch' => $entry['catch'],
        ];
    }
}
