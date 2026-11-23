<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ChannelInsightsController;
use App\Http\Controllers\Api\ChannelPostingScheduleController;
use App\Http\Controllers\Api\ChannelQueueController;
use App\Http\Controllers\Api\DiscordController;
use App\Http\Controllers\Api\IdeaController;
use App\Http\Controllers\Api\IdeaStageController;
use App\Http\Controllers\Api\InstagramAudioController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\PinterestBoardController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PostApprovalController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostNoteController;
use App\Http\Controllers\Api\PostRecurrenceController;
use App\Http\Controllers\Api\RepurposeController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\TikTokCreatorInfoController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\App\MediaImportController;
use App\Tantra\Http\Controllers\Api\GatewayBoostController;
use App\Tantra\Http\Controllers\Api\GatewayCanvaController;
use App\Tantra\Http\Controllers\Api\GatewayChannelsController;
use App\Tantra\Http\Controllers\Api\GatewayComposerController;
use App\Tantra\Http\Controllers\Api\GatewayContentController;
use App\Tantra\Http\Controllers\Api\GatewayIdeasController;
use App\Tantra\Http\Controllers\Api\GatewayKitController;
use App\Tantra\Http\Controllers\Api\GatewayLibraryController;
use App\Tantra\Http\Controllers\Api\GatewayMediaController;
use App\Tantra\Http\Controllers\Api\GatewayOpsController;
use App\Tantra\Http\Controllers\Api\GatewayReviewController;
use App\Tantra\Http\Controllers\Api\GatewayTasksController;
use Illuminate\Support\Facades\Route;

/*
 * tantra: the engine API for the Tantra gateway (docs/crm/PRD_ONE_API_AND_MCP.md section 9). The same controllers as
 * routes/api.php under /api/gateway, for calls the Tantra CRM Worker signs on behalf of one Tantra key. No personal
 * access token: AuthenticateTantraGateway checks the signature and runs the request as the member's engine user with
 * the same workspace and subscription rules as the API. Engine API keys are not routes here (the api-keys routes are left out).
 * Generated from routes/api.php by the A-01 gateway patch; when the engine adds an API route, add it here as well.
 */
Route::middleware(['api', 'tantra.gateway', 'throttle:api'])
    ->prefix('api/gateway')
    ->name('tantra.gateway.')
    ->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('api.analytics.index');
        Route::get('/analytics/publications/{publication}', [AnalyticsController::class, 'showPublication'])
            ->whereUuid('publication')
            ->name('api.analytics.publications.show');

        Route::post('/uploads', [UploadController::class, 'create'])->name('api.uploads.create');

        Route::get('/posts', [PostController::class, 'index'])->name('api.posts.index');
        Route::post('/posts/batch', [PostController::class, 'storeBatch'])->name('api.posts.batch.store');
        Route::post('/posts', [PostController::class, 'store'])->name('api.posts.store');
        Route::get('/posts/{post}', [PostController::class, 'show'])->name('api.posts.show');
        Route::put('/posts/{post}', [PostController::class, 'update'])->name('api.posts.update');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('api.posts.destroy');
        Route::patch('/posts/{post}/recurrence', [PostRecurrenceController::class, 'update'])->name('api.posts.recurrence.update');
        Route::delete('/posts/{post}/recurrence', [PostRecurrenceController::class, 'destroy'])->name('api.posts.recurrence.destroy');
        Route::post('/posts/{post}/media', [PostController::class, 'storeMedia'])->name('api.posts.store-media');
        Route::post('/posts/{post}/media/from-upload', [PostController::class, 'attachMediaFromUpload'])->name('api.posts.attach-media-from-upload');
        Route::post('/posts/{post}/media/from-url', [PostController::class, 'attachMediaFromUrl'])->name('api.posts.attach-media-from-url');
        Route::get('/posts/{post}/metrics', [PostController::class, 'metrics'])->name('api.posts.metrics');
        Route::get('/posts/{post}/preview', [PostController::class, 'preview'])->name('api.posts.preview');
        Route::get('/posts/{post}/notes', [PostNoteController::class, 'index'])->name('api.posts.notes.index');
        Route::post('/posts/{post}/notes', [PostNoteController::class, 'store'])->name('api.posts.notes.store');
        Route::put('/posts/{post}/notes/{note}', [PostNoteController::class, 'update'])->name('api.posts.notes.update');
        Route::delete('/posts/{post}/notes/{note}', [PostNoteController::class, 'destroy'])->name('api.posts.notes.destroy');
        Route::post('/posts/{post}/approve', [PostApprovalController::class, 'approve'])->name('api.posts.approve');
        Route::post('/posts/{post}/reject', [PostApprovalController::class, 'reject'])->name('api.posts.reject');

        Route::get('/content-types', [PlatformController::class, 'contentTypes'])->name('api.content-types');

        Route::get('/workspace', [WorkspaceController::class, 'show'])->name('api.workspace.show');

        Route::get('/signatures', [SignatureController::class, 'index'])->name('api.signatures.index');
        Route::post('/signatures', [SignatureController::class, 'store'])->name('api.signatures.store');
        Route::put('/signatures/{signature}', [SignatureController::class, 'update'])->name('api.signatures.update');
        Route::delete('/signatures/{signature}', [SignatureController::class, 'destroy'])->name('api.signatures.destroy');

        Route::get('/ideas', [IdeaController::class, 'index'])->name('api.ideas.index');
        Route::post('/ideas', [IdeaController::class, 'store'])->name('api.ideas.store');
        Route::delete('/ideas', [IdeaController::class, 'bulkDestroy'])->name('api.ideas.bulk-destroy');
        Route::get('/ideas/{idea}', [IdeaController::class, 'show'])->whereUuid('idea')->name('api.ideas.show');
        Route::put('/ideas/{idea}', [IdeaController::class, 'update'])->whereUuid('idea')->name('api.ideas.update');
        Route::delete('/ideas/{idea}', [IdeaController::class, 'destroy'])->whereUuid('idea')->name('api.ideas.destroy');
        Route::post('/ideas/{idea}/duplicate', [IdeaController::class, 'duplicate'])->whereUuid('idea')->name('api.ideas.duplicate');
        Route::put('/ideas/{idea}/move', [IdeaController::class, 'move'])->whereUuid('idea')->name('api.ideas.move');

        Route::get('/idea-stages', [IdeaStageController::class, 'index'])->name('api.idea-stages.index');
        Route::post('/idea-stages', [IdeaStageController::class, 'store'])->name('api.idea-stages.store');
        Route::put('/idea-stages/order', [IdeaStageController::class, 'reorder'])->name('api.idea-stages.reorder');
        Route::put('/idea-stages/{ideaStage}', [IdeaStageController::class, 'update'])->whereUuid('ideaStage')->name('api.idea-stages.update');
        Route::delete('/idea-stages/{ideaStage}', [IdeaStageController::class, 'destroy'])->whereUuid('ideaStage')->name('api.idea-stages.destroy');

        Route::get('/labels', [LabelController::class, 'index'])->name('api.labels.index');
        Route::post('/labels', [LabelController::class, 'store'])->name('api.labels.store');
        Route::put('/labels/{label}', [LabelController::class, 'update'])->name('api.labels.update');
        Route::delete('/labels/{label}', [LabelController::class, 'destroy'])->name('api.labels.destroy');

        Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('api.social-accounts.index');
        Route::get('/social-accounts/{account}/boards', [PinterestBoardController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('api.social-accounts.boards');
        Route::post('/social-accounts/{account}/boards', [PinterestBoardController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('api.social-accounts.boards.store');
        Route::get('/social-accounts/{account}/tiktok-creator-info', [TikTokCreatorInfoController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('api.social-accounts.tiktok-creator-info');
        Route::get('/social-accounts/{account}/instagram-audio', [InstagramAudioController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('api.social-accounts.instagram-audio');
        Route::get('/social-accounts/{account}/instagram-audio/{audioId}', [InstagramAudioController::class, 'show'])
            ->whereNumber('audioId')
            ->middleware('throttle:60,1')
            ->name('api.social-accounts.instagram-audio.show');
        Route::get('/social-accounts/{account}/channels', [DiscordController::class, 'channels'])
            ->middleware('throttle:60,1')
            ->name('api.social-accounts.channels');

        Route::get('/channels/{account}/insights', [ChannelInsightsController::class, 'show'])
            ->whereUuid('account')
            ->name('api.channels.insights.show');
        Route::get('/channels/{account}/insights/publications', [ChannelInsightsController::class, 'publications'])
            ->whereUuid('account')
            ->name('api.channels.insights.publications');
        Route::get('/channels/{account}/posting-schedule', [ChannelPostingScheduleController::class, 'show'])
            ->whereUuid('account')
            ->name('api.channels.posting-schedule.show');
        Route::put('/channels/{account}/posting-schedule', [ChannelPostingScheduleController::class, 'update'])
            ->whereUuid('account')
            ->name('api.channels.posting-schedule.update');
        Route::post('/channels/{account}/posting-schedule/generate', [ChannelPostingScheduleController::class, 'generate'])
            ->whereUuid('account')
            ->name('api.channels.posting-schedule.generate');
        Route::post('/channels/{account}/posting-schedule/copy', [ChannelPostingScheduleController::class, 'copy'])
            ->whereUuid('account')
            ->name('api.channels.posting-schedule.copy');
        Route::get('/channels/{account}/queue/slots', [ChannelQueueController::class, 'slots'])
            ->whereUuid('account')
            ->name('api.channels.queue.slots');
        Route::put('/channels/{account}/queue/order', [ChannelQueueController::class, 'reorder'])
            ->whereUuid('account')
            ->name('api.channels.queue.order');
        Route::put('/channels/{account}/queue/slot', [ChannelQueueController::class, 'moveToSlot'])
            ->whereUuid('account')
            ->name('api.channels.queue.slot');

        Route::get('/repurpose-source-formats', [RepurposeController::class, 'sourceFormats'])->name('api.repurpose-source-formats.index');
        Route::get('/repurposes', [RepurposeController::class, 'index'])->name('api.repurposes.index');
        Route::post('/repurposes', [RepurposeController::class, 'store'])->name('api.repurposes.store');
        Route::get('/repurposes/{repurpose}', [RepurposeController::class, 'show'])->name('api.repurposes.show');
        Route::put('/repurposes/{repurpose}', [RepurposeController::class, 'update'])->name('api.repurposes.update');
        Route::get('/repurposes/{repurpose}/items', [RepurposeController::class, 'items'])->name('api.repurposes.items');
        Route::post('/repurposes/{repurpose}/activate', [RepurposeController::class, 'activate'])->name('api.repurposes.activate');
        Route::post('/repurposes/{repurpose}/pause', [RepurposeController::class, 'pause'])->name('api.repurposes.pause');
        Route::post('/repurposes/{repurpose}/resume', [RepurposeController::class, 'resume'])->name('api.repurposes.resume');
        Route::post('/repurposes/{repurpose}/disable', [RepurposeController::class, 'disable'])->name('api.repurposes.disable');
        Route::delete('/repurposes/{repurpose}', [RepurposeController::class, 'destroy'])->name('api.repurposes.destroy');

        Route::get('/webhooks', [WebhookController::class, 'index'])->name('api.webhooks.index');
        Route::post('/webhooks', [WebhookController::class, 'store'])->name('api.webhooks.store');
        Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('api.webhooks.show');
        Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('api.webhooks.update');
        Route::post('/webhooks/{webhook}/send-test', [WebhookController::class, 'sendTest'])->name('api.webhooks.send-test');
        Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->name('api.webhooks.rotate-secret');
        Route::get('/webhooks/{webhook}/logs', [WebhookController::class, 'logs'])->name('api.webhooks.logs');
        Route::post('/webhooks/{webhook}/logs/{webhookLog}/replay', [WebhookController::class, 'replay'])->name('api.webhooks.replay');
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('api.webhooks.destroy');

        // tantra: Tantra's native Content > Create and Review (AF-09). Not generated from routes/api.php: engine Tantra routes.
        Route::get('/tantra/formats', [GatewayContentController::class, 'formats'])->name('tantra.formats');
        Route::get('/tantra/create', [GatewayContentController::class, 'pieces'])->name('tantra.create.index');
        Route::post('/tantra/create', [GatewayContentController::class, 'create'])->name('tantra.create.store');
        Route::get('/tantra/create/{piece}', [GatewayContentController::class, 'piece'])->whereUuid('piece')->name('tantra.create.show');
        Route::get('/tantra/review', [GatewayContentController::class, 'review'])->name('tantra.review.index');
        Route::put('/tantra/review/{item}', [GatewayContentController::class, 'edit'])->whereUuid('item')->name('tantra.review.update');
        Route::post('/tantra/review/{item}/approve', [GatewayContentController::class, 'approve'])->whereUuid('item')->name('tantra.review.approve');
        Route::post('/tantra/review/{item}/reject', [GatewayContentController::class, 'reject'])->whereUuid('item')->name('tantra.review.reject');

        // tantra: the rest of Review, post by hand, the composer's media, link preview, Tantra ideas, feeds and templates.
        Route::get('/tantra/review/{item}/comments', [GatewayReviewController::class, 'comments'])->whereUuid('item')->name('tantra.review.comments');
        Route::post('/tantra/review/{item}/comments', [GatewayReviewController::class, 'comment'])->whereUuid('item')->name('tantra.review.comment');
        Route::post('/tantra/review/{item}/request-changes', [GatewayReviewController::class, 'requestChanges'])->whereUuid('item')->name('tantra.review.request-changes');
        Route::post('/tantra/review/{item}/sign-off', [GatewayReviewController::class, 'signOff'])->whereUuid('item')->name('tantra.review.sign-off');
        Route::post('/tantra/review/{item}/unapprove', [GatewayReviewController::class, 'unapprove'])->whereUuid('item')->name('tantra.review.unapprove');

        Route::get('/tantra/tasks', [GatewayTasksController::class, 'index'])->name('tantra.tasks.index');
        Route::post('/tantra/tasks/{task}/posted', [GatewayTasksController::class, 'posted'])->whereUuid('task')->name('tantra.tasks.posted');
        Route::post('/tantra/tasks/{task}/skip', [GatewayTasksController::class, 'skip'])->whereUuid('task')->name('tantra.tasks.skip');

        Route::get('/tantra/media', [GatewayMediaController::class, 'index'])->name('tantra.media.index');
        Route::post('/tantra/media', [GatewayMediaController::class, 'store'])->name('tantra.media.store');
        Route::patch('/tantra/media/{media}', [GatewayMediaController::class, 'alt'])->whereUuid('media')->name('tantra.media.alt');
        Route::get('/tantra/link-preview', [GatewayMediaController::class, 'linkPreview'])->middleware('throttle:60,1')->name('tantra.link-preview');

        Route::get('/tantra/ideas', [GatewayIdeasController::class, 'index'])->name('tantra.ideas.index');
        Route::post('/tantra/ideas', [GatewayIdeasController::class, 'store'])->name('tantra.ideas.store');
        Route::patch('/tantra/ideas/{idea}', [GatewayIdeasController::class, 'update'])->whereUuid('idea')->name('tantra.ideas.update');
        Route::delete('/tantra/ideas/{idea}', [GatewayIdeasController::class, 'destroy'])->whereUuid('idea')->name('tantra.ideas.destroy');
        Route::post('/tantra/ideas/{idea}/to-review', [GatewayIdeasController::class, 'toReview'])->whereUuid('idea')->name('tantra.ideas.to-review');

        Route::get('/tantra/feeds', [GatewayLibraryController::class, 'feeds'])->name('tantra.feeds');
        Route::get('/tantra/post-templates', [GatewayLibraryController::class, 'postTemplates'])->name('tantra.post-templates');

        // tantra: One Content app PRD 3.5, 3.8 and 4.1. Boost consent, the post kit, channel care, the composer and the Engine card.
        $clerk = 'user_[A-Za-z0-9]{10,64}';
        Route::put('/tantra/team/members/{member}/boost', [GatewayBoostController::class, 'boost'])->where('member', $clerk)->name('tantra.team.boost');
        Route::post('/tantra/team/members/{member}/match', [GatewayBoostController::class, 'match'])->where('member', $clerk)->name('tantra.team.match');
        Route::get('/tantra/team/members/{member}/status', [GatewayBoostController::class, 'status'])->where('member', $clerk)->name('tantra.team.status');

        Route::get('/tantra/posts/{ref}/thread', [GatewayKitController::class, 'show'])->whereUuid('ref')->name('tantra.kit.show');
        Route::put('/tantra/posts/{ref}/thread', [GatewayKitController::class, 'update'])->whereUuid('ref')->name('tantra.kit.update');

        Route::post('/tantra/connect/bluesky', [GatewayChannelsController::class, 'connectBluesky'])->middleware('throttle:10,1')->name('tantra.connect.bluesky');
        Route::post('/tantra/connect/mastodon', [GatewayChannelsController::class, 'connectMastodon'])->middleware('throttle:10,1')->name('tantra.connect.mastodon');
        Route::get('/tantra/connect/{token}/candidates', [GatewayChannelsController::class, 'candidates'])->where('token', '[A-Za-z0-9]{48}')->name('tantra.connect.candidates');
        Route::post('/tantra/connect/{token}/select', [GatewayChannelsController::class, 'select'])->where('token', '[A-Za-z0-9]{48}')->name('tantra.connect.select');
        Route::put('/tantra/channels/order', [GatewayChannelsController::class, 'reorder'])->name('tantra.channels.order');
        Route::delete('/tantra/channels/{account}', [GatewayChannelsController::class, 'disconnect'])->whereUuid('account')->name('tantra.channels.disconnect');
        Route::get('/tantra/channels/{account}/options', [GatewayChannelsController::class, 'options'])->whereUuid('account')->middleware('throttle:60,1')->name('tantra.channels.options');

        Route::post('/tantra/composer/assist', [GatewayComposerController::class, 'assist'])->name('tantra.composer.assist');
        Route::get('/tantra/unsplash', [GatewayComposerController::class, 'unsplash'])->middleware('throttle:unsplash')->name('tantra.unsplash');
        Route::post('/tantra/posts/{post}/duplicate', [GatewayComposerController::class, 'duplicate'])->whereUuid('post')->name('tantra.posts.duplicate');

        // tantra: One Content app PRD 4.1, Design in Canva from the CRM Write media picker, and the export it starts.
        Route::get('/tantra/canva/start', [GatewayCanvaController::class, 'start'])->middleware('throttle:30,1')->name('tantra.canva.start');
        Route::get('/tantra/media/imports/{import}', [MediaImportController::class, 'show'])->whereUuid('import')->name('tantra.media.imports.show');

        Route::get('/tantra/ops', [GatewayOpsController::class, 'summary'])->name('tantra.ops');
        Route::post('/tantra/ops/failed-jobs/{uuid}/retry', [GatewayOpsController::class, 'retry'])->whereUuid('uuid')->name('tantra.ops.retry');

    });
