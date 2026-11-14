<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Tantra\Agent\AgentRunner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** tantra: agent runners authenticate with their own token, which can only read, log and finish jobs (PRD 25.5). */
class EnsureRunnerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $runner = filled($token = $request->bearerToken()) ? AgentRunner::findByToken((string) $token) : null;
        if ($runner === null) {
            return response()->json(['message' => 'Runner token not recognised.'], Response::HTTP_UNAUTHORIZED);
        }
        $request->attributes->set('tantra_runner', $runner);

        return $next($request);
    }
}
