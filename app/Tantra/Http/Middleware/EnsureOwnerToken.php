<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Models\AccessToken;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken as PassportAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the engine import routes act across the Account's workspaces with
 * the Account owner's personal API key. Upstream's workspace.token binds a key
 * to one workspace; here the key must belong to the Account owner, and a route
 * that works on one workspace names it with `workspace_id` (query or body),
 * which must be in the owner's Account.
 */
class EnsureOwnerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $passportToken = $user?->token();

        if (! $passportToken instanceof PassportAccessToken) {
            return response()->json(['message' => 'Token not found.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = AccessToken::query()->find($passportToken->oauth_access_token_id);

        if ($token === null || $token->expires_at?->isPast()) {
            return response()->json(['message' => 'Token not found or expired.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $token->isPersonalAccessToken()) {
            return response()->json(['message' => 'Personal access token required.'], Response::HTTP_FORBIDDEN);
        }

        if ($user->account?->owner_id !== $user->id) {
            return response()->json(['message' => 'Only the Account owner can use the engine import API.'], Response::HTTP_FORBIDDEN);
        }

        $workspaceId = $request->input('workspace_id', $request->query('workspace_id'));

        if (filled($workspaceId)) {
            $workspace = Workspace::query()
                ->where('account_id', $user->account_id)
                ->find((string) $workspaceId);

            if ($workspace === null) {
                return response()->json(['message' => 'Workspace not found in this Account.'], Response::HTTP_NOT_FOUND);
            }

            $user->setRelation('currentWorkspace', $workspace);
            $user->current_workspace_id = $workspace->id;
            $request->attributes->set('tantra_workspace', $workspace);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
