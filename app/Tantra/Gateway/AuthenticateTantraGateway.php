<?php

declare(strict_types=1);

namespace App\Tantra\Gateway;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Engagement\EngagementChannels;
use App\Tantra\Review\ReviewGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: lets the Tantra CRM Worker call the engine API as one Tantra member, with one Tantra key on its side.
 * Only on /api/gateway/* (app/Tantra/Http/routes-gateway.php).
 *
 * The person is the engine account linked to the member's Clerk user (clerk_user_id), as for Tantra Chat. M2: a
 * teammate the engine has never seen is set up on the spot (LinkTeammate, as a member) when the Worker signed their
 * email (X-Tantra-Member-Email) and the workspace is the one the engine links to the signed Tantra team; otherwise they
 * are told their account is being linked. The workspace is the one named in X-Tantra-Content-Workspace, else their
 * current one.
 *
 * M2 open access (PRD 2026-11-23 decision 8): being a member of the workspace is enough; every member uses every
 * feature. Team management and billing keep their own admin or owner checks in their controllers and policies
 * (GatewayBoostController: changing another member's boost or LinkedIn needs manageTeam). The account needs an active
 * subscription. The scopes the Worker signed narrow the call further: reads need content:read, writes content:write,
 * approvals and recurring runs content:publish.
 */
final class AuthenticateTantraGateway
{
    public const PER_MINUTE = 600;

    /** Routes that decide what goes out, take that back, sign off its numbers or start a recurring run. */
    public const PUBLISH_PATH = '#/(approve|unapprove|sign-off|reject|activate|resume|pause|disable)$#';

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
        $pin = (string) $request->header('X-Tantra-Content-Workspace', '');
        $user ??= $this->linkTeammate($claims, $pin);
        if ($user === null) {
            return response()->json(['message' => 'Your Tantra account is being linked to Content. Try again in a minute.', 'code' => 'no_engine_access'], Response::HTTP_FORBIDDEN);
        }

        $limit = "tantra-gateway:{$user->id}";
        if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
            return response()->json(['message' => 'Too many requests; try again in a minute.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($limit, 60);

        $workspace = $pin !== '' ? Workspace::query()->find($pin) : $user->currentWorkspace;
        if ($workspace === null || ! $user->can('view', $workspace)) {
            return response()->json(['message' => 'No workspace selected in the content engine.', 'code' => 'no_workspace'], Response::HTTP_FORBIDDEN);
        }
        if (! config('trypost.self_hosted') && ! $workspace->account?->hasAppAccess()) {
            return response()->json(['message' => 'Active subscription required.'], Response::HTTP_PAYMENT_REQUIRED);
        }

        // M2: the CRM checked the team's approver rule before calling; the review owner rule does not apply here.
        ReviewGate::trustCrmCaller();
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $user->setRelation('currentWorkspace', $workspace);
        $user->current_workspace_id = $workspace->id;

        return $next($request);
    }

    /**
     * M2: the engine user for a teammate it has never seen, made from the signed email in the workspace linked to the
     * signed Tantra team: the pinned one when it is that team's, else the team's own. Null when either is missing.
     *
     * @param  array{team: string, member: string, scopes: list<string>, email: ?string}  $claims
     */
    private function linkTeammate(array $claims, string $pin): ?User
    {
        $email = $claims['email'] ?? null;
        $linked = EngagementChannels::engineWorkspaces($claims['team'] !== '' ? $claims['team'] : null);
        if ($email === null || $linked === []) {
            return null;
        }
        $id = $pin !== '' ? $pin : $linked[0];
        if (! in_array($id, $linked, true)) {
            return null;
        }
        $workspace = Workspace::query()->find($id);

        return $workspace === null ? null : LinkTeammate::join($workspace, $claims['member'], $email);
    }
}
