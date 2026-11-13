<?php

declare(strict_types=1);

use App\Tantra\Http\Controllers\Api\BatchController;
use App\Tantra\Http\Controllers\Api\ChannelScheduleController;
use App\Tantra\Http\Controllers\Api\MessagesController;
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
        Route::get('/messages/{engineBatchId}/{enginePostId}', [MessagesController::class, 'show'])->where(['engineBatchId' => '[A-Za-z0-9_-]{1,32}', 'enginePostId' => '[A-Za-z0-9_-]{1,32}'])->name('messages.show');
        Route::get('/results/{engineBatchId}', [ResultsController::class, 'show'])->where('engineBatchId', '[A-Za-z0-9_-]{1,32}')->name('results.show');
        // tantra: each channel's posting days and times (PRD 25.6).
        Route::get('/channels/{channel}/schedule', [ChannelScheduleController::class, 'show'])->whereUuid('channel')->name('channels.schedule.show');
        Route::put('/channels/{channel}/schedule', [ChannelScheduleController::class, 'update'])->whereUuid('channel')->name('channels.schedule.update');
    });

// tantra: Zernio's signed publish events (PRD 10.4 step 6). No session, no CSRF.
Route::middleware(['api', 'throttle:120,1'])
    ->post('tantra/webhooks/zernio', ZernioWebhookController::class)
    ->name('tantra.webhooks.zernio');

// tantra: the pingers' health report (PRD 8.5).
Route::middleware(['api', 'throttle:30,1'])
    ->get('tantra/health', HealthController::class)
    ->name('tantra.health');
