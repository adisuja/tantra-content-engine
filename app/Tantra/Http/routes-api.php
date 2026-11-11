<?php

declare(strict_types=1);

use App\Tantra\Http\Controllers\Api\BatchController;
use App\Tantra\Http\Controllers\Api\ProfileController;
use App\Tantra\Http\Controllers\Api\UploadController;
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
    });
