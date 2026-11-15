<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Auth\ClerkLogin;
use App\Tantra\Auth\ClerkToken;
use App\Tantra\Auth\InvalidClerkToken;
use App\Tantra\Embed\Embed;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * tantra: the Engine inside the Tantra CRM's Content tab (PRD 2026-11-15, 4.2).
 *
 * GET tantra/embed is a bare page the CRM frames. It asks the CRM for the
 * person's Clerk session token (postMessage), posts it to session(), then
 * opens the page it was given. Every Content tab load proves again who is
 * signed in to the CRM, so a different CRM user never inherits the last
 * Engine session. Behind TANTRA_EMBED only; TANTRA_CLERK_LOGIN and the
 * standalone sign in pages are untouched.
 */
class EmbedController extends Controller
{
    public function boot(Request $request): View
    {
        abort_unless(Embed::enabled(), 404);

        return view('tantra::embed', [
            'to' => Embed::safePath($request->query('to')),
            'parents' => Embed::parents(),
            'sessionUrl' => route('tantra.embed.session', absolute: false),
        ]);
    }

    /**
     * The connect popup's first page (PRD 2026-11-15, 4.4). It marks this
     * tab as the popup with the frame's nonce (sessionStorage and
     * window.name, never window.opener, which a network's
     * Cross-Origin-Opener-Policy can cut), then opens the connect page.
     */
    public function connectWindow(Request $request): View
    {
        abort_unless(Embed::enabled(), 404);

        $nonce = $request->query('nonce');
        abort_unless(is_string($nonce) && preg_match(Embed::CONNECT_NONCE, $nonce) === 1, 404);

        return view('tantra::connect-window', [
            'nonce' => $nonce,
            'to' => Embed::safePath($request->query('to'), '/settings/workspace/channels'),
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        abort_unless(Embed::enabled(), 404);

        // 1. Verify the token; nothing changes when it does not.
        $token = $request->input('token');
        if (! is_string($token) || $token === '' || strlen($token) > 8192) {
            return response()->json(['code' => 'token_invalid'], 401);
        }

        try {
            $claims = ClerkToken::verify($token);
        } catch (InvalidClerkToken $e) {
            Log::warning('tantra.embed: token refused', ['reason' => $e->getMessage()]);

            return response()->json(['code' => 'token_invalid'], 401);
        } catch (RuntimeException $e) {
            // ClerkUnavailable: the signing keys could not be read (a Clerk outage).
            Log::warning('tantra.embed: token check failed', ['reason' => $e->getMessage()]);

            return response()->json(['code' => 'server'], 503);
        }

        // 2. Who that Clerk user is in the Engine.
        try {
            ['user' => $user, 'email' => $email] = ClerkLogin::resolve($claims);
        } catch (RuntimeException $e) {
            Log::warning('tantra.embed: sign in failed', ['reason' => $e->getMessage()]);
            // Never leave the previous person's session in place when we cannot tell who this is.
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['code' => 'server'], 503);
        }

        // 3. Someone else holds this browser's Engine session: end it first,
        // without cycling their remember token (their other devices stay in).
        $guard = Auth::guard('web');
        if ($guard->check() && ($user === null || $guard->id() !== $user->getAuthIdentifier())) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // 4. A CRM user with no Engine login. Without CLERK_SECRET_KEY the
        // email stays unknown, which is a setup gap, not a "no access".
        if ($user === null) {
            if ($email === null && blank(config('tantra.clerk.secret_key'))) {
                Log::warning('tantra.embed: no verified email for the Clerk user; set CLERK_SECRET_KEY or add email to the session token', ['sub' => $claims['sub']]);

                return response()->json(['code' => 'server'], 503);
            }

            return response()->json(['code' => 'no_access'], 403);
        }

        // 5. Sign in, unless already signed in as this person.
        if (! $guard->check()) {
            $request->session()->regenerate();
            // No remember cookie: every Content mount proves who is signed in again.
            $guard->login($user, remember: false);
        }

        // 6. The boot page now opens the page it was given.
        return response()->json(['ok' => true, 'email' => $user->email]);
    }
}
