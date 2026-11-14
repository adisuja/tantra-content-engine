<?php

declare(strict_types=1);

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Tantra\Http\Controllers\OpsController;
use App\Tantra\Http\Controllers\RebrandController;
use App\Tantra\Http\Controllers\RepostController;
use App\Tantra\Http\Controllers\RepurposingController;
use App\Tantra\Http\Controllers\TemplatesController;
use App\Tantra\Http\Controllers\Web\ChannelAppController;
use App\Tantra\Http\Controllers\Web\ClerkSessionController;
use App\Tantra\Http\Controllers\Web\ConnectorController;
use App\Tantra\Http\Controllers\Web\DeveloperAppsController;
use App\Tantra\Http\Controllers\Web\FormatsController;
use App\Tantra\Http\Controllers\Web\IdeaReviewController;
use App\Tantra\Http\Controllers\Web\InsightsController;
use App\Tantra\Http\Controllers\Web\JoinController;
use App\Tantra\Http\Controllers\Web\ManualTaskController;
use App\Tantra\Http\Controllers\Web\OpenChannelController;
use App\Tantra\Http\Controllers\Web\PreConnectController;
use App\Tantra\Http\Controllers\Web\PreviewLinkController;
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
            Route::post('/messages/{channel}', [ReviewItemActionController::class, 'message'])->where('channel', '[a-z_]{2,32}')->name('message');
        });
    });

// tantra: the public preview of one post's asset (no sign in; long random token).
Route::middleware(['web', 'throttle:60,1'])
    ->get('previewlink/{token}', PreviewLinkController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('tantra.previewlink');

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

// tantra: the Team page: consent, expertise, Unipile matches, posting times (PRD 10.5, 10.9, 25.6).
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
        Route::post('/{member}/clerk-unlink', [TeamController::class, 'clerkUnlink'])->whereUuid('member')->name('clerk-unlink');
        Route::put('/channels/{channel}/schedule', [TeamController::class, 'schedule'])->whereUuid('channel')->name('channels.schedule');
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

// tantra: a teammate's welcome link (signed, 30 days) opens the set-password page.
Route::middleware(['web', 'signed', 'throttle:6,1'])
    ->get('tantra/join/{user}', JoinController::class)
    ->whereUuid('user')
    ->name('tantra.join');

// tantra: the step before a network's sign in while its app is in testing or has no keys (PreConnectGate).
Route::middleware(['web', 'auth', EnsureHasWorkspace::class])->group(function (): void {
    Route::get('connect/{network}/prepare', [PreConnectController::class, 'show'])
        ->where('network', '[a-z-]+')->name('tantra.connect.prepare');
    Route::post('connect/{network}/request-access', [PreConnectController::class, 'requestAccess'])
        ->where('network', '[a-z-]+')->middleware('throttle:10,1')->name('tantra.connect.request-access');
});

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

// tantra: sign in with the usetantra.com login (Clerk), behind TANTRA_CLERK_LOGIN (2026-11-14).
Route::middleware(['web', 'throttle:20,1'])
    ->post('auth/clerk/session', [ClerkSessionController::class, 'store'])
    ->name('tantra.clerk.session');
Route::middleware(['web'])
    ->get('auth/clerk/no-access', [ClerkSessionController::class, 'noAccess'])
    ->name('tantra.clerk.no-access');

// tantra: live lookups for the composer's format settings (YouTube playlists, Pinterest board sections).
Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class, 'throttle:30,1'])
    ->prefix('tantra/formats')
    ->name('tantra.formats.')
    ->group(function (): void {
        Route::get('youtube/{account}/playlists', [FormatsController::class, 'youtubePlaylists'])->whereUuid('account')->name('youtube.playlists');
        Route::get('pinterest/{account}/boards/{board}/sections', [FormatsController::class, 'pinterestSections'])->whereUuid('account')->name('pinterest.sections');
    });

// tantra: Studio ops (PRD 25.4): agent jobs, Claude accounts, runners. TANTRA_ADMIN_EMAILS only; 404 for anyone else.
Route::middleware(['web', 'auth', 'tantra.admin'])
    ->prefix('tantra/ops')
    ->name('tantra.ops.')
    ->group(function (): void {
        Route::get('/', [OpsController::class, 'page'])->middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])->name('index');
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

// tantra: content templates under Create (PRD 25.9).
Route::middleware(['web', 'auth'])
    ->prefix('tantra/templates')
    ->name('tantra.templates.')
    ->group(function (): void {
        Route::get('/', [TemplatesController::class, 'page'])->middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])->name('index');
        Route::post('/intake', [TemplatesController::class, 'intake'])->name('intake');
        Route::get('/{template}', [TemplatesController::class, 'show'])->whereUuid('template')->name('show');
    });

// tantra: the Repurposing tab under Create (PRD 25.10).
Route::middleware(['web', 'auth'])
    ->prefix('tantra/repurposing')
    ->name('tantra.repurposing.')
    ->group(function (): void {
        Route::get('/', [RepurposingController::class, 'page'])->middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])->name('index');
        Route::put('/cadence', [RepurposingController::class, 'cadence'])->name('cadence');
        Route::put('/rules/{network}', [RepurposingController::class, 'rules'])->where('network', '[a-z]{1,16}')->name('rules');
    });

// tantra: Repost a scheduled or published post to more channels (PRD 25.10).
Route::middleware(['web', 'auth', 'throttle:30,1'])->group(function (): void {
    Route::get('tantra/repost/targets', [RepostController::class, 'targets'])->name('tantra.repost.targets');
    Route::post('tantra/repost', [RepostController::class, 'store'])->name('tantra.repost.store');
});
// tantra: Send to review from a Tantra idea card on the Ideas board (PRD 25.8).
Route::middleware(['web', 'auth', EnsureAccountReady::class, EnsureHasWorkspace::class, 'throttle:30,1'])
    ->post('tantra/ideas/{idea}/to-review', IdeaReviewController::class)
    ->whereUuid('idea')
    ->name('tantra.ideas.to-review');

// tantra: profile rebrands on the Team page (PRD 25.11). Only the person applies their own.
Route::middleware(['web', 'auth', 'throttle:30,1'])->group(function (): void {
    Route::get('tantra/rebrands', [RebrandController::class, 'index'])->name('tantra.rebrands.index');
    Route::post('tantra/team/{member}/rebrand', [RebrandController::class, 'start'])->whereUuid('member')->name('tantra.rebrands.start');
    Route::post('tantra/rebrands/{rebrand}/headline', [RebrandController::class, 'headline'])->whereUuid('rebrand')->name('tantra.rebrands.headline');
    Route::post('tantra/rebrands/{rebrand}/dismiss', [RebrandController::class, 'dismiss'])->whereUuid('rebrand')->name('tantra.rebrands.dismiss');
});
