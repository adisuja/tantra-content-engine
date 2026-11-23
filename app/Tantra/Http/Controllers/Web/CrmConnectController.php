<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Auth\MastodonController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\SafeHttpFetcher;
use App\Support\Social\PendingConnection;
use App\Tantra\Channels\CrmConnectLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: RF-10, the signed link from POST publish/connect (CrmConnectLink). It works once and for ten minutes: it signs the
 * person in (ending anyone else's Engine session in this browser first, as the embed does), opens the team's workspace, keeps
 * the return URL for this network and starts the Engine's own connect route. A link that is used, or a person who may no
 * longer add channels there, goes straight back with result=failed.
 */
class CrmConnectController extends Controller
{
    public function __invoke(Request $request, SafeHttpFetcher $fetcher): Response
    {
        $network = (string) $request->query('network');
        $return = CrmConnectLink::safeReturn($request->query('return'));
        abort_if($return === null || ! in_array($network, CrmConnectLink::NETWORKS, true), 404);
        $fail = fn (): RedirectResponse => redirect()->away(CrmConnectLink::withResult($return, $network, CrmConnectLink::FAILED));

        // Once only: a link copied out of the address bar cannot sign anyone in again.
        if (! Cache::add('tantra:crm-connect:'.sha1((string) $request->query('signature')), 1, now()->addMinutes(CrmConnectLink::TTL_MINUTES + 1))) {
            return $fail();
        }

        $user = User::query()->find($request->query('u'));
        $workspace = Workspace::query()->find($request->query('w'));
        $route = CrmConnectLink::startRoute($network);
        $instance = $network === 'mastodon' ? (string) $request->query('instance', '') : '';
        if ($network === 'mastodon' && ($instance === '' || ! Platform::Mastodon->isEnabled())) {
            return $fail();
        }
        if (! $user instanceof User || ! $workspace instanceof Workspace || ($route === null && $instance === '') || ! $user->can('manageAccounts', $workspace)) {
            return $fail();
        }

        $guard = Auth::guard('web');
        if ($guard->check() && $guard->id() !== $user->getAuthIdentifier()) {
            $guard->logoutCurrentDevice();
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        if (! $guard->check()) {
            $request->session()->regenerate();
            $guard->login($user, remember: false);
        }

        $user->switchWorkspace($workspace);
        CrmConnectLink::remember($network, $return);

        if ($instance !== '') {
            // One Content app PRD 4.1: the engine's own Mastodon step, with the server the person named in the CRM.
            $request->query->set(CrmConnectLink::FLAG, '1');
            $request->merge(['instance' => $instance]);
            $answer = app(MastodonController::class)->authorizeInstance($request, $fetcher);
            $target = $answer->headers->get('X-Inertia-Location') ?? $answer->headers->get('Location');

            return is_string($target) && str_starts_with($target, $instance.'/oauth/authorize') ? redirect()->away($target) : $fail();
        }

        return redirect()->route($route, [CrmConnectLink::FLAG => '1']);
    }

    /**
     * GET tantra/crm-connect/back?result=: the end of the round trip. It leaves for the Tantra app's invite page with the network
     * and the result; an Inertia request (Finish, Back) gets a full page load there. With no round trip under way it opens
     * the channels page.
     */
    public function back(Request $request): Response
    {
        $url = CrmConnectLink::take((string) $request->query('result'));

        return $url === null ? redirect()->to(PendingConnection::defaultReturnUrl()) : Inertia::location($url);
    }
}
