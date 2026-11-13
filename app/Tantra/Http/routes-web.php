<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Tantra\Http\Controllers\Web\ChannelAppController;
use App\Tantra\Http\Controllers\Web\ConnectorController;
use App\Tantra\Http\Controllers\Web\DeveloperAppsController;
use App\Tantra\Http\Controllers\Web\FormatsController;
use App\Tantra\Http\Controllers\Web\InsightsController;
use App\Tantra\Http\Controllers\Web\JoinController;
use App\Tantra\Http\Controllers\Web\ManualTaskController;
use App\Tantra\Http\Controllers\Web\OpenChannelController;
use App\Tantra\Http\Controllers\Web\ReconnectController;
use App\Tantra\Http\Controllers\Web\ReviewItemActionController;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use App\Tantra\Http\Controllers\Web\TeamController;
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
            Route::post('/dms/{reply}', [ReviewItemActionController::class, 'dmSent'])->whereUuid('reply')->name('dm-sent');
            Route::post('/thread/{index}', [ReviewItemActionController::class, 'thread'])->whereNumber('index')->name('thread');
        });
    });

// tantra: posts published by hand (PRD 12).
Route::middleware(['web', 'auth'])
    ->prefix('tantra/tasks')
    ->name('tantra.tasks.')
    ->group(function (): void {
        Route::get('/', [ManualTaskController::class, 'index'])
            ->middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])
            ->name('index');
        Route::post('/{task}/posted', [ManualTaskController::class, 'posted'])->whereUuid('task')->name('posted');
        Route::post('/{task}/skip', [ManualTaskController::class, 'skip'])->whereUuid('task')->name('skip');
    });

// tantra: the Team page: consent, expertise, Unipile matches (PRD 10.5, 10.9).
Route::middleware(['web', 'auth'])
    ->prefix('tantra/team')
    ->name('tantra.team.')
    ->group(function (): void {
        Route::get('/', [TeamController::class, 'index'])
            ->middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])
            ->name('index');
        Route::post('/consent', [TeamController::class, 'consent'])->name('consent');
        Route::post('/expertise', [TeamController::class, 'expertise'])->name('expertise');
        Route::post('/match', [TeamController::class, 'match'])->name('match');
    });

// tantra: the team dashboard (PRD 10.6).
Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class])
    ->get('tantra/insights', [InsightsController::class, 'index'])
    ->name('tantra.insights');

// tantra: reconnect a LinkedIn channel from the reminder (PRD M3).
Route::middleware(['web', 'auth'])
    ->get('tantra/reconnect/{account}', ReconnectController::class)
    ->whereUuid('account')
    ->name('tantra.reconnect');
Route::middleware(['web', 'auth'])
    ->get('tantra/approvals/{workspace}', [ReconnectController::class, 'approvals'])
    ->whereUuid('workspace')
    ->name('tantra.approvals');

// tantra: a teammate's welcome link (signed, 14 days) opens the set-password page.
Route::middleware(['web', 'signed', 'throttle:6,1'])
    ->get('tantra/join/{user}', JoinController::class)
    ->whereUuid('user')
    ->name('tantra.join');

// tantra: the Connectors tab: AI providers, Claude and Codex subscriptions, the Tantra API.
Route::middleware(['web', 'auth'])
    ->prefix('settings/workspace/connectors')
    ->name('tantra.connectors.')
    ->group(function (): void {
        Route::get('/', [ConnectorController::class, 'index'])
            ->middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])
            ->name('index');
        // tantra: the Channels section, developer app keys per network.
        Route::put('/channels/{app}', [ChannelAppController::class, 'update'])
            ->middleware(EnsureAccountReady::class)->name('channels.update');
        Route::delete('/channels/{app}', [ChannelAppController::class, 'destroy'])
            ->middleware(EnsureAccountReady::class)->name('channels.destroy');
        Route::put('/{provider}', [ConnectorController::class, 'update'])->name('update');
        Route::delete('/{provider}', [ConnectorController::class, 'destroy'])->name('destroy');
        Route::post('/{provider}/default', [ConnectorController::class, 'makeDefault'])->name('default');
        Route::post('/{provider}/test', [ConnectorController::class, 'test'])->name('test');
        Route::post('/{provider}/models', [ConnectorController::class, 'models'])->name('models');
    });

// tantra: Settings > Admin > Developer apps, the instance owner's keys page (Adi 2026-11-14).
Route::middleware(['web', 'auth', EnsureAccountReady::class])
    ->get('settings/developer-apps', [DeveloperAppsController::class, 'index'])
    ->name('tantra.developer-apps.index');

// tantra: the sidebar lists every workspace's channels; one in another workspace switches there first (PRD D1).
Route::middleware(['web', 'auth', EnsureAccountReady::class])
    ->post('tantra/channels/{account}/open', OpenChannelController::class)
    ->whereUuid('account')
    ->name('tantra.channels.open');

// tantra: live lookups for the composer's format settings (YouTube playlists, Pinterest board sections).
Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class, 'throttle:30,1'])
    ->prefix('tantra/formats')
    ->name('tantra.formats.')
    ->group(function (): void {
        Route::get('youtube/{account}/playlists', [FormatsController::class, 'youtubePlaylists'])->whereUuid('account')->name('youtube.playlists');
        Route::get('pinterest/{account}/boards/{board}/sections', [FormatsController::class, 'pinterestSections'])->whereUuid('account')->name('pinterest.sections');
    });
