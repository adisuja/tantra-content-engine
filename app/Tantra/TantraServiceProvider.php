<?php

declare(strict_types=1);

namespace App\Tantra;

use App\Enums\SocialAccount\Platform;
use App\Tantra\Console\CreateOwnerCommand;
use App\Tantra\Ops\LeanHorizon;
use Illuminate\Support\ServiceProvider;

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

        if ($this->app->runningInConsole()) {
            $this->commands([CreateOwnerCommand::class]);
        }

        if ($this->app->environment('production') && config('tantra.lean_workers.enabled')) {
            $this->useLeanHorizon();
        }
    }

    private function useLeanHorizon(): void
    {
        $plan = LeanHorizon::plan(
            (array) config('horizon.defaults', []),
            Platform::enabledQueues(),
            (int) config('tantra.lean_workers.processes', 3),
        );

        config([
            'horizon.defaults' => $plan,
            // Horizon starts only the supervisors named for the environment,
            // so production must name each one; upstream's names are gone.
            'horizon.environments.production' => array_map(
                fn (array $supervisor): array => ['maxProcesses' => (int) ($supervisor['maxProcesses'] ?? 1)],
                $plan,
            ),
        ]);
    }
}
