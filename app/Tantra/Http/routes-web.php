<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Tantra\Http\Controllers\Web\JoinController;
use App\Tantra\Http\Controllers\Web\ReviewItemActionController;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use Illuminate\Support\Facades\Route;

// tantra: review studio (PRD 10.2). Pages render with Inertia; actions answer JSON.
Route::middleware(['web', 'auth'])
    ->prefix('tantra/review')
    ->name('tantra.review.')
    ->group(function (): void {
        Route::middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])->group(function (): void {
            Route::get('/', [ReviewPageController::class, 'index'])->name('index');
            Route::get('/{batch}', [ReviewPageController::class, 'show'])->whereUuid('batch')->name('show');
        });

        Route::prefix('items/{item}')->whereUuid('item')->name('items.')->group(function (): void {
            Route::post('/approve', [ReviewItemActionController::class, 'approve'])->name('approve');
            Route::post('/unapprove', [ReviewItemActionController::class, 'unapprove'])->name('unapprove');
            Route::post('/request-changes', [ReviewItemActionController::class, 'requestChanges'])->name('request-changes');
            Route::post('/reject', [ReviewItemActionController::class, 'reject'])->name('reject');
            Route::post('/comments', [ReviewItemActionController::class, 'comment'])->name('comment');
            Route::post('/sign-off', [ReviewItemActionController::class, 'signOff'])->name('sign-off');
        });
    });

// tantra: a teammate's welcome link (signed, 14 days) opens the set-password page.
Route::middleware(['web', 'signed', 'throttle:6,1'])
    ->get('tantra/join/{user}', JoinController::class)
    ->whereUuid('user')
    ->name('tantra.join');
