<?php

declare(strict_types=1);

use App\Tantra\Http\Controllers\Web\ReviewItemActionController;
use Illuminate\Support\Facades\Route;

// tantra: review studio (PRD 10.2). Pages arrive in M1.3; actions answer JSON.
Route::middleware(['web', 'auth'])
    ->prefix('tantra/review')
    ->name('tantra.review.')
    ->group(function (): void {
        Route::prefix('items/{item}')->whereUuid('item')->name('items.')->group(function (): void {
            Route::post('/approve', [ReviewItemActionController::class, 'approve'])->name('approve');
            Route::post('/unapprove', [ReviewItemActionController::class, 'unapprove'])->name('unapprove');
            Route::post('/request-changes', [ReviewItemActionController::class, 'requestChanges'])->name('request-changes');
            Route::post('/reject', [ReviewItemActionController::class, 'reject'])->name('reject');
            Route::post('/comments', [ReviewItemActionController::class, 'comment'])->name('comment');
            Route::post('/sign-off', [ReviewItemActionController::class, 'signOff'])->name('sign-off');
        });
    });
