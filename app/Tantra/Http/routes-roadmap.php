<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Tantra\Http\Controllers\Api\RoadmapController;
use App\Tantra\Http\Controllers\RoadmapFeedController;
use App\Tantra\Http\Controllers\Web\RoadmapAdminController;
use App\Tantra\Http\Controllers\Web\RoadmapPageController;
use Illuminate\Support\Facades\Route;

// tantra: the public product roadmap (docs/tantra-roadmap.md). The page needs
// the web group for Inertia; nothing on it needs a login.
Route::middleware(['web'])
    ->get('roadmap', RoadmapPageController::class)
    ->name('tantra.roadmap');

// Read only files for feed readers, crawlers and agents: no session, no cookie.
Route::middleware(['throttle:120,1'])->group(function (): void {
    Route::get('roadmap.json', [RoadmapFeedController::class, 'json'])->name('tantra.roadmap.json');
    Route::get('roadmap/feed', [RoadmapFeedController::class, 'atom'])->name('tantra.roadmap.feed');
    Route::get('roadmap/llms.txt', [RoadmapFeedController::class, 'llms'])->name('tantra.roadmap.llms');
});

// The public read API and the request form (also used from usetantra.com, so
// no session and no CSRF; CORS covers api/*).
Route::middleware(['api', 'throttle:120,1'])
    ->prefix('api/roadmap')
    ->name('tantra.api.roadmap.')
    ->group(function (): void {
        Route::get('/', [RoadmapController::class, 'index'])->name('index');
        Route::post('/requests', [RoadmapController::class, 'request'])->name('requests.store');
    });

// The write API: the instance owner's API key, or any key with roadmap:write.
Route::middleware(['api', 'auth:api', 'throttle:api'])
    ->prefix('api/roadmap/items')
    ->name('tantra.api.roadmap.items.')
    ->group(function (): void {
        Route::post('/', [RoadmapController::class, 'store'])->name('store');
        Route::patch('/{item}', [RoadmapController::class, 'update'])->whereUlid('item')->name('update');
        Route::post('/{item}/move', [RoadmapController::class, 'move'])->whereUlid('item')->name('move');
        Route::delete('/{item}', [RoadmapController::class, 'destroy'])->whereUlid('item')->name('destroy');
    });

// The owner's screen: Settings > Roadmap.
Route::middleware(['web', 'auth', EnsureAccountReady::class])
    ->prefix('settings/workspace/roadmap')
    ->name('tantra.roadmap.admin.')
    ->group(function (): void {
        Route::get('/', [RoadmapAdminController::class, 'index'])->name('index');
        Route::post('/items', [RoadmapAdminController::class, 'store'])->name('store');
        Route::put('/items/{item}', [RoadmapAdminController::class, 'update'])->whereUlid('item')->name('update');
        Route::post('/items/{item}/move', [RoadmapAdminController::class, 'move'])->whereUlid('item')->name('move');
        Route::delete('/items/{item}', [RoadmapAdminController::class, 'destroy'])->whereUlid('item')->name('destroy');
    });
