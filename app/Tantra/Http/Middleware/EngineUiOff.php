<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Tantra\UiOff\UiRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: One Content app PRD 4.1 (critic B6). With TANTRA_UI_OFF on, the engine has no screens: a UI GET goes to the
 * matching page in the Tantra app (302; an Inertia visit gets a full page load there) and any other UI request answers 410.
 * Allowlisted routes (UiRoutes::ALLOW) pass untouched. First in the web group, so nothing else runs for a refused request.
 * Rollback: turn the switch off.
 */
class EngineUiOff
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! UiRoutes::enabled() || ! $route instanceof Route || UiRoutes::allowed($route, $request->getMethod())) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $message = 'This screen moved to Tantra. Use the Tantra app.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'code' => 'engine_ui_off'], Response::HTTP_GONE)
                : response($message, Response::HTTP_GONE);
        }

        $url = UiRoutes::target($route);

        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }
}
