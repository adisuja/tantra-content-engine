<?php

declare(strict_types=1);

namespace App\Tantra;

use App\Enums\SocialAccount\Platform;
use App\Tantra\Console\CreateOwnerCommand;
use App\Tantra\Console\MapProfileCommand;
use App\Tantra\Http\Middleware\EnsureOwnerToken;
use Illuminate\Routing\Router;
use App\Tantra\Ops\LeanHorizon;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Entry point for everything Tantra adds to the TryPost fork.
 *
 * Kept in its own namespace so upstream merges stay clean. Registered with a
 * single line in bootstrap/providers.php.
 */
class TantraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Our migrations live apart from upstream's, in database/migrations/tantra.
        $this->loadMigrationsFrom(database_path('migrations/tantra'));

        $this->app->make(Router::class)->aliasMiddleware('tantra.owner-token', EnsureOwnerToken::class);
        $this->loadRoutesFrom(__DIR__.'/Http/routes-api.php');
        $this->loadRoutesFrom(__DIR__.'/Http/routes-web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateOwnerCommand::class, MapProfileCommand::class]);
        }

        if ($this->app->environment('production') && config('tantra.lean_workers.enabled')) {
            $this->useLeanHorizon();
        }

        if (config('tantra.branding.enabled')) {
            $this->useTantraBranding();
        }
    }

    /**
     * Swaps TryPost's name and outward links for Tantra's wherever upstream
     * still holds its own default, so an explicit env value always wins.
     */
    private function useTantraBranding(): void
    {
        $name = (string) config('tantra.branding.name');
        $defaults = [
            'app.name' => ['TryPost.it', $name],
            'mail.from.name' => ['TryPost', $name],
            'trypost.user_agent' => ['TryPost.it/1.0 (+https://trypost.it)', (string) config('tantra.branding.user_agent')],
            // No Tantra terms or privacy pages exist for the Engine yet, so the
            // auth screens hide the sentence instead of linking to TryPost's.
            'trypost.legal.terms_url' => ['https://trypost.it/terms', ''],
            'trypost.legal.privacy_url' => ['https://trypost.it/privacy', ''],
        ];

        foreach ($defaults as $key => [$upstream, $tantra]) {
            if (config($key) === $upstream) {
                config([$key => $tantra]);
            }
        }

        Inertia::share('tantra', fn (): array => [
            'branding' => true,
            'name' => $name,
            'sourceUrl' => (string) config('tantra.branding.source_url'),
        ]);
    }

    private function useLeanHorizon(): void
    {
        $plan = LeanHorizon::plan(
            (array) config('horizon.defaults', []),
            Platform::enabledQueues(),
            (int) config('tantra.lean_workers.processes', 3),
        );

        // Horizon checks the supervisors named under every environment when
        // it starts (ProvisioningPlan), not only the current one, so each
        // environment must name only supervisors that still exist.
        $sizes = array_map(
            fn (array $supervisor): array => ['maxProcesses' => (int) ($supervisor['maxProcesses'] ?? 1)],
            $plan,
        );

        config([
            'horizon.defaults' => $plan,
            'horizon.environments' => array_map(
                fn (): array => $sizes,
                (array) config('horizon.environments', []) + ['production' => []],
            ),
        ]);
    }
}
