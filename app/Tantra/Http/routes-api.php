<?php

declare(strict_types=1);

use App\Tantra\Http\Controllers\Api\AgentRunnerController;
use App\Tantra\Http\Controllers\Api\BatchController;
use App\Tantra\Http\Controllers\Api\ChannelScheduleController;
use App\Tantra\Http\Controllers\Api\IdeaController;
use App\Tantra\Http\Controllers\Api\ProfileController;
use App\Tantra\Http\Controllers\Api\ResultsController;
use App\Tantra\Http\Controllers\Api\UploadController;
use App\Tantra\Http\Controllers\HealthController;
use App\Tantra\Http\Controllers\OpsController;
use App\Tantra\Http\Controllers\RepostController;
use App\Tantra\Http\Controllers\RepurposingController;
use App\Tantra\Http\Controllers\TemplatesController;
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
        Route::post('/templates', [TemplatesController::class, 'seed'])->name('templates.seed');
        Route::post('/templates/{key}/preview', [TemplatesController::class, 'preview'])->where('key', '[A-Z]{1,4}\\d{1,3}')->name('templates.preview');
        // Ideas with a network, a phone preview and a path to Review (PRD 25.8).
        Route::get('/ideas', [IdeaController::class, 'index'])->name('ideas.index');
        Route::post('/ideas', [IdeaController::class, 'upsert'])->name('ideas.upsert');
        Route::patch('/ideas/{idea}', [IdeaController::class, 'update'])->whereUuid('idea')->name('ideas.update');
        Route::post('/ideas/{idea}/to-review', [IdeaController::class, 'toReview'])->whereUuid('idea')->name('ideas.to-review');
        Route::delete('/ideas/{idea}', [IdeaController::class, 'destroy'])->whereUuid('idea')->name('ideas.destroy');
        Route::get('/results/{engineBatchId}', [ResultsController::class, 'show'])->where('engineBatchId', '[A-Za-z0-9_-]{1,32}')->name('results.show');
        // tantra: each channel's posting days and times (PRD 25.6).
        Route::get('/channels/{channel}/schedule', [ChannelScheduleController::class, 'show'])->whereUuid('channel')->name('channels.schedule.show');
        Route::put('/channels/{channel}/schedule', [ChannelScheduleController::class, 'update'])->whereUuid('channel')->name('channels.schedule.update');
    });

// tantra: content templates and repurposing, as the token's own user (PRD 25.9, 25.10).
Route::middleware(['api', 'auth:api', 'throttle:api'])
    ->prefix('api/tantra')
    ->name('tantra.api.')
    ->group(function (): void {
        Route::get('/templates', [TemplatesController::class, 'index'])->name('templates.index');
        Route::post('/templates/intake', [TemplatesController::class, 'intake'])->name('templates.intake');
        Route::get('/repurposing', [RepurposingController::class, 'show'])->name('repurposing.show');
        Route::get('/repost/targets', [RepostController::class, 'targets'])->name('repost.targets');
        Route::post('/repost', [RepostController::class, 'store'])->name('repost.store');
        Route::put('/repurposing/cadence', [RepurposingController::class, 'cadence'])->name('repurposing.cadence');
        Route::put('/repurposing/rules/{network}', [RepurposingController::class, 'rules'])->where('network', '[a-z]{1,16}')->name('repurposing.rules');
        Route::post('/repurposing/presets', [RepurposingController::class, 'presets'])->name('repurposing.presets');
    });

// tantra: Studio ops over the API (PRD 25.4): any admin's own token (review M2).
Route::middleware(['api', 'auth:api', 'tantra.admin', 'throttle:api'])
    ->prefix('api/tantra/ops')
    ->name('tantra.api.ops.')
    ->group(function (): void {
        Route::get('/jobs', [OpsController::class, 'jobs'])->name('jobs.index');
        Route::post('/jobs', [OpsController::class, 'enqueue'])->name('jobs.store');
        Route::get('/jobs/{job}', [OpsController::class, 'job'])->whereUuid('job')->name('jobs.show');
        Route::post('/jobs/{job}/run', [OpsController::class, 'run'])->whereUuid('job')->name('jobs.run');
        Route::post('/jobs/{job}/retry', [OpsController::class, 'retry'])->whereUuid('job')->name('jobs.retry');
        Route::post('/jobs/{job}/cancel', [OpsController::class, 'cancel'])->whereUuid('job')->name('jobs.cancel');
        Route::post('/accounts', [OpsController::class, 'storeAccount'])->name('accounts.store');
        Route::patch('/accounts/{account}', [OpsController::class, 'updateAccount'])->whereUuid('account')->name('accounts.update');
        Route::delete('/accounts/{account}', [OpsController::class, 'destroyAccount'])->whereUuid('account')->name('accounts.destroy');
        Route::post('/accounts/{account}/test', [OpsController::class, 'testAccount'])->whereUuid('account')->name('accounts.test');
        Route::post('/runners', [OpsController::class, 'storeRunner'])->name('runners.store');
        Route::delete('/runners/{runner}', [OpsController::class, 'revokeRunner'])->whereUuid('runner')->name('runners.revoke');
    });

// tantra: agent runners (the cloud task, a laptop) with their own token (PRD 25.5).
Route::middleware(['api', 'tantra.runner', 'throttle:120,1'])
    ->prefix('api/tantra/agent')
    ->name('tantra.api.agent.')
    ->group(function (): void {
        Route::post('/claim', [AgentRunnerController::class, 'claim'])->name('claim');
        Route::get('/jobs/{job}', [AgentRunnerController::class, 'show'])->whereUuid('job')->name('jobs.show');
        Route::post('/jobs/{job}/log', [AgentRunnerController::class, 'log'])->whereUuid('job')->name('jobs.log');
        Route::post('/jobs/{job}/complete', [AgentRunnerController::class, 'complete'])->whereUuid('job')->name('jobs.complete');
        Route::post('/jobs/{job}/fail', [AgentRunnerController::class, 'fail'])->whereUuid('job')->name('jobs.fail');
    });

// tantra: Zernio's signed publish events (PRD 10.4 step 6). No session, no CSRF.
Route::middleware(['api', 'throttle:120,1'])
    ->post('tantra/webhooks/zernio', ZernioWebhookController::class)
    ->name('tantra.webhooks.zernio');

// tantra: the pingers' health report (PRD 8.5).
Route::middleware(['api', 'throttle:30,1'])
    ->get('tantra/health', HealthController::class)
    ->name('tantra.health');
