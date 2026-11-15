<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * tantra: prints the engine's /api routes as JSON (method, path, controller@action), so the Tantra CRM inventory can
 * refresh its snapshot of the engine API without parsing PHP (npm run inventory, docs/crm/api/INVENTORY.md section 7).
 * The gateway copies under /api/gateway are left out. Read only.
 */
class RoutesJsonCommand extends Command
{
    protected $signature = 'tantra:routes-json';

    protected $description = 'Print the engine API routes as JSON for the Tantra inventory';

    public function handle(): int
    {
        $rows = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/') && ! str_starts_with($r->uri(), 'api/gateway/') && ! str_starts_with($r->uri(), 'api/tantra/'))
            ->flatMap(fn (LaravelRoute $r) => collect($r->methods())->reject(fn (string $m) => in_array($m, ['HEAD', 'OPTIONS'], true))->map(fn (string $m) => [
                'method' => $m,
                'path' => '/'.$r->uri(),
                'action' => ltrim(str_replace('App\\Http\\Controllers\\', '', $r->getActionName()), '\\'),
                'middleware' => array_values(array_map('strval', $r->gatherMiddleware())),
            ]))
            ->sortBy(fn (array $r) => $r['path'].' '.$r['method'])
            ->values();
        $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
