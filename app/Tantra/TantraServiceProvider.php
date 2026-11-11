<?php

declare(strict_types=1);

namespace App\Tantra;

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
    }
}
