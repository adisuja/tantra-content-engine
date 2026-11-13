<?php

declare(strict_types=1);

use App\Tantra\Http\Controllers\Api\BatchController;
use App\Tantra\Http\Controllers\Api\IdeaController;
use App\Tantra\Http\Controllers\Api\ProfileController;
use App\Tantra\Http\Controllers\Api\ResultsController;
use App\Tantra\Http\Controllers\Api\UploadController;
use App\Tantra\Http\Controllers\HealthController;
use App\Tantra\Http\Controllers\ZernioWebhookController;
use Illuminate\Support\Facades\Route;

// tantra: engine import API, Account owner's API key only (EnsureOwnerToken).
Route::middleware(['api', 'auth:api', 'tantra.owner-token', 'throttle:api'])
    ->prefix('api/tantra')
    ->name('tantra.api.')
    ->group(function (): void {
        Route::get('/profiles', [ProfileController::class, 'index'])->name('profiles.index');
        Route::post('/uploads', [UploadController::class, 'media'])->name('uploads.store');
        Route::post('/previews', [UploadController::class, 'preview'])->name('previews.store');
        Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
        Route::get('/batches/{batch}/feedback', [BatchController::class, 'feedback'])->whereUuid('batch')->name('batches.feedback');
        // Ideas with a network, a phone preview and a path to Review (PRD 25.8).
        Route::get('/ideas', [IdeaController::class, 'index'])->name('ideas.index');
        Route::post('/ideas', [IdeaController::class, 'upsert'])->name('ideas.upsert');
        Route::patch('/ideas/{idea}', [IdeaController::class, 'update'])->whereUuid('idea')->name('ideas.update');
        Route::post('/ideas/{idea}/to-review', [IdeaController::class, 'toReview'])->whereUuid('idea')->name('ideas.to-review');
        Route::delete('/ideas/{idea}', [IdeaController::class, 'destroy'])->whereUuid('idea')->name('ideas.destroy');
        Route::get('/results/{engineBatchId}', [ResultsController::class, 'show'])->where('engineBatchId', '[A-Za-z0-9_-]{1,32}')->name('results.show');
    });

// tantra: Zernio's signed publish events (PRD 10.4 step 6). No session, no CSRF.
Route::middleware(['api', 'throttle:120,1'])
    ->post('tantra/webhooks/zernio', ZernioWebhookController::class)
    ->name('tantra.webhooks.zernio');

// tantra: the pingers' health report (PRD 8.5).
Route::middleware(['api', 'throttle:30,1'])
    ->get('tantra/health', HealthController::class)
    ->name('tantra.health');
