<?php

declare(strict_types=1);

namespace App\Tantra\Chat;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: lets Tantra Chat use the engine's MCP tools as the person chatting. Only on /mcp/tantra-chat.
 *
 * The person is the engine account linked to their Clerk user (clerk_user_id, set when they sign in to the engine
 * with their Tantra login, App\Tantra\Auth\ClerkLogin). No email matching: someone who never signed in to the
 * engine with Tantra gets no access. The workspace is their current one, with the API's access and subscription
 * checks; the chat pins it with X-Tantra-Workspace, so a workspace switched in another tab stops the chat instead of
 * sending its actions somewhere else.
 */
final class AuthenticateTantraChat
{
    public const PER_MINUTE = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('tantra_chat.content_secret');
        if (strlen($secret) < 32) {
            return response()->json(['message' => 'Tantra Chat is not set up on this engine.'], Response::HTTP_NOT_FOUND);
        }

        $clerkId = ChatToken::clerkUserId((string) $request->bearerToken(), $secret);
        if ($clerkId === null) {
            return response()->json(['message' => 'Invalid or expired chat session.'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var User|null $user */
        $user = User::query()->where('clerk_user_id', $clerkId)->first();
        if ($user === null) {
            return response()->json(['message' => 'Sign in to the content engine once with your Tantra account to use it from the chat.'], Response::HTTP_FORBIDDEN);
        }

        $limit = "tantra-chat:{$user->id}";
        if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
            return response()->json(['message' => 'Too many chat requests; try again in a minute.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($limit, 60);

        $workspace = $user->currentWorkspace;
        if ($workspace === null || ! $user->can('view', $workspace)) {
            return response()->json(['message' => 'No workspace selected in the content engine.'], Response::HTTP_FORBIDDEN);
        }
        $pinned = $request->header('X-Tantra-Workspace');
        if (filled($pinned) && $pinned !== (string) $workspace->id) {
            return response()->json(['message' => 'Your content engine workspace changed since this chat started. Start a new chat to use the new one.'], Response::HTTP_CONFLICT);
        }
        if (! config('trypost.self_hosted') && ! $workspace->account?->hasAppAccess()) {
            return response()->json(['message' => 'Active subscription required.'], Response::HTTP_PAYMENT_REQUIRED);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $user->setRelation('currentWorkspace', $workspace);

        return $next($request);
    }
}
