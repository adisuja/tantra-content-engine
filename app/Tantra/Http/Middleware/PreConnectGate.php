<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Tantra\Channels\ConnectFailures;
use App\Tantra\Channels\PreConnect;
use App\Tantra\Http\Controllers\Web\PreConnectController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: on the connect routes. While a network's developer app is in
 * development, or has no keys yet, starting its sign in shows the pre-connect
 * step first instead of the network's own error page (Meta's raw JSON for an
 * account that is not a tester, Google's "access blocked", a 403 here).
 *
 * Passes through: anything but a GET to a connect route, people who may not
 * manage the workspace's channels (the controller refuses them as before),
 * reconnects (that account was let in once already) and "Continue" from the
 * step itself.
 */
class PreConnectGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = $request->isMethod('GET') ? PreConnect::networkFromRoute($request->route()?->getName()) : null;
        if ($platform === null) {
            return $next($request);
        }

        // A new attempt: the network's words from the last one no longer apply.
        ConnectFailures::forgetDetail();

        if (! PreConnect::needed($platform)
            || (filled($request->query('reconnect')) && PreConnect::ready($platform))
            || ($request->query(PreConnect::CONTINUE_FLAG) === '1' && PreConnect::ready($platform))) {
            return $next($request);
        }

        // Someone who may not manage channels is refused by the controller, as before.
        $workspace = $request->user()?->currentWorkspace;
        if ($workspace === null || ! $request->user()->can('manageAccounts', $workspace)) {
            return $next($request);
        }

        // No keys yet: the step says so, still as the refusal it was.
        if (! PreConnect::ready($platform)) {
            return PreConnectController::render($request, $platform, Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('tantra.connect.prepare', [PreConnect::slug($platform), ...$request->query()]);
    }
}
