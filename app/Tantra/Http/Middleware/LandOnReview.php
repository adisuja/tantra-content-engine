<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Tantra\Review\Models\WorkspaceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the Review screen is where people land (PRD 10.2, 2026-11-12).
 * TryPost sends everyone to the calendar after signing in; the first time a
 * session opens the calendar in a Tantra workspace, it opens Review instead.
 * The calendar stays one click away afterwards.
 */
class LandOnReview
{
    public const SESSION_KEY = 'tantra.landed';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->header('X-Inertia') && $request->routeIs('app.calendar')
            && $request->query->count() === 0 && $request->hasSession() && ! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, true);
            $workspace = $request->user()?->currentWorkspace;
            if ($workspace !== null && WorkspaceSettings::query()->whereKey($workspace->id)->exists()) {
                return redirect()->route('tantra.review.index');
            }
        }

        return $next($request);
    }
}
