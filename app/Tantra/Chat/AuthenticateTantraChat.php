<?php

declare(strict_types=1);

namespace App\Tantra\Chat;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: lets Tantra Chat use the engine's MCP tools as the person chatting, matched by verified email
 * (owner decision 2026-11-15: one person, one email across the CRM, the engine and Tantra).
 *
 * Only on the /mcp/tantra-chat route. The chat Worker mints the token for the signed-in CRM member, so the email
 * is the one the CRM verified; the engine account must have the same email and have verified it. The workspace is
 * the person's current one, with the same access and subscription checks the API applies.
 */
final class AuthenticateTantraChat
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('tantra_chat.signing_secret');
        if (strlen($secret) < 32) {
            return response()->json(['message' => 'Tantra Chat is not set up on this engine.'], Response::HTTP_NOT_FOUND);
        }

        $email = ChatToken::email((string) $request->bearerToken(), $secret);
        if ($email === null) {
            return response()->json(['message' => 'Invalid or expired chat session.'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var User|null $user */
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->whereNotNull('email_verified_at')->first();
        if ($user === null) {
            return response()->json(['message' => 'No content engine account with this verified email.'], Response::HTTP_FORBIDDEN);
        }

        $workspace = $user->currentWorkspace;
        if ($workspace === null || ! $user->can('view', $workspace)) {
            return response()->json(['message' => 'No workspace selected in the content engine.'], Response::HTTP_FORBIDDEN);
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
