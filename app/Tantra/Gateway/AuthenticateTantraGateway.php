<?php

declare(strict_types=1);

namespace App\Tantra\Gateway;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: lets the Tantra CRM Worker call the engine API as one Tantra member, with one Tantra key on its side.
 * Only on /api/gateway/* (app/Tantra/Http/routes-gateway.php).
 *
 * The person is the engine account linked to the member's Clerk user (clerk_user_id), as for Tantra Chat: no email
 * matching, so someone who never signed in to the engine with Tantra gets no access. The workspace is the one named in
 * X-Tantra-Content-Workspace, else their current one. The rules are the API's own: the person must be able to view the
 * workspace and manage its team (the personal access token rule of LoadWorkspaceFromToken), and the account needs an
 * active subscription. The scopes the Worker signed narrow the call further: reads need content:read, writes
 * content:write, approvals and recurring runs content:publish.
 */
final class AuthenticateTantraGateway
{
    public const PER_MINUTE = 600;

    /** Routes that decide what goes out or starts a recurring run. */
    public const PUBLISH_PATH = '#/(approve|reject|activate|resume|pause|disable)$#';

    public function handle(Request $request, Closure $next): Response
    {
        $secrets = (array) config('tantra_gateway.secrets', []);
        if (! config('tantra_gateway.enabled') || $secrets === []) {
            return response()->json(['message' => 'The gateway is not set up on this engine.'], Response::HTTP_NOT_FOUND);
        }

        $claims = GatewaySignature::verify($request, $secrets);
        if (is_string($claims)) {
            return response()->json(['message' => 'Invalid gateway signature.', 'code' => $claims], Response::HTTP_UNAUTHORIZED);
        }
        if (preg_match('/^user_[A-Za-z0-9]{10,64}$/', $claims['member']) !== 1) {
            return response()->json(['message' => 'Invalid gateway member.', 'code' => 'bad_member'], Response::HTTP_UNAUTHORIZED);
        }

        $scopes = $claims['scopes'];
        $method = strtoupper($request->method());
        $need = in_array($method, ['GET', 'HEAD'], true) ? 'content:read'
            : (preg_match(self::PUBLISH_PATH, $request->path()) === 1 ? 'content:publish' : 'content:write');
        if (! in_array($need, $scopes, true)) {
            return response()->json(['message' => "This key needs the {$need} scope.", 'code' => 'missing_scope'], Response::HTTP_FORBIDDEN);
        }

        /** @var User|null $user */
        $user = User::query()->where('clerk_user_id', $claims['member'])->first();
        if ($user === null) {
            return response()->json(['message' => 'Sign in to the content engine once with your Tantra account to use it from Tantra.', 'code' => 'no_engine_access'], Response::HTTP_FORBIDDEN);
        }

        $limit = "tantra-gateway:{$user->id}";
        if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
            return response()->json(['message' => 'Too many requests; try again in a minute.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($limit, 60);

        $pin = (string) $request->header('X-Tantra-Content-Workspace', '');
        $workspace = $pin !== '' ? Workspace::query()->find($pin) : $user->currentWorkspace;
        if ($workspace === null || ! $user->can('view', $workspace)) {
            return response()->json(['message' => 'No workspace selected in the content engine.', 'code' => 'no_workspace'], Response::HTTP_FORBIDDEN);
        }
        if (! $user->can('manageTeam', $workspace)) {
            return response()->json(['message' => 'Insufficient workspace permissions.', 'code' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }
        if (! config('trypost.self_hosted') && ! $workspace->account?->hasAppAccess()) {
            return response()->json(['message' => 'Active subscription required.'], Response::HTTP_PAYMENT_REQUIRED);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $user->setRelation('currentWorkspace', $workspace);
        $user->current_workspace_id = $workspace->id;

        return $next($request);
    }
}
