<?php

declare(strict_types=1);

use App\Http\Middleware\Mcp\AuthenticateMcp;
use App\Http\Middleware\Mcp\ChallengeChatGptToolCalls;
use App\Http\Middleware\Mcp\LoadMcpWorkspace;
use App\Mcp\Servers\TryPostServer;
use App\Tantra\Chat\AuthenticateTantraChat;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:mcp-oauth')->group(fn () => Mcp::oauthRoutes());

Mcp::web('/mcp/trypost', TryPostServer::class)
    ->middleware([ChallengeChatGptToolCalls::class, AuthenticateMcp::class.':api', LoadMcpWorkspace::class])
    ->name('mcp.trypost');

// tantra: Tantra Chat uses the same tools as the person chatting (App\Tantra\Chat\AuthenticateTantraChat).
Mcp::web('/mcp/tantra-chat', TryPostServer::class)
    ->middleware(['throttle:120,1', AuthenticateTantraChat::class])
    ->name('mcp.tantra-chat');
