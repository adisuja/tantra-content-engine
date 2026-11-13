<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Auth\ClerkLogin;
use App\Tantra\Auth\ClerkToken;
use App\Tantra\Auth\InvalidClerkToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * tantra: sign in with the usetantra.com login (Clerk). The page mounts Clerk's
 * sign in; once Clerk has a session the page posts its session token here, the
 * server verifies it (ClerkToken) and starts the Laravel session.
 */
class ClerkSessionController extends Controller
{
    // Flashed by sign out: the page ends the Clerk session before showing the form.
    public const SIGNED_OUT = 'tantra_clerk_signed_out';

    public static function page(Request $request, string $state = 'sign-in'): Response
    {
        return Inertia::render('tantra/auth/ClerkSignIn', [
            'state' => $state,
            'email' => $state === 'no-access' ? session('tantra_clerk_email') : null,
            'signedOut' => (bool) session(self::SIGNED_OUT),
            'publishableKey' => (string) config('tantra.clerk.publishable_key'),
            'frontendApi' => (string) config('tantra.clerk.frontend_api'),
            'sessionUrl' => route('tantra.clerk.session'),
            'passwordUrl' => route('login', ['password' => 1]),
            'loginUrl' => route('login'),
            'homeUrl' => 'https://usetantra.com',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(ClerkLogin::enabled(), 404);
        $token = $request->input('token');
        abort_unless(is_string($token) && $token !== '' && strlen($token) <= 8192, 422);

        try {
            $claims = ClerkToken::verify($token);
            ['user' => $user, 'email' => $email] = ClerkLogin::resolve($claims);
        } catch (InvalidClerkToken|RuntimeException $e) {
            Log::warning('tantra.clerk: sign in refused', ['reason' => $e->getMessage()]);

            return redirect()->route('login')->withErrors(['clerk' => 'We could not finish signing you in. Try again.']);
        }

        if ($user === null) {
            if ($email === null) {
                Log::warning('tantra.clerk: no verified email for the Clerk user; set CLERK_SECRET_KEY or add email to the session token', ['sub' => $claims['sub']]);
            }

            return redirect()->route('tantra.clerk.no-access')->with('tantra_clerk_email', $email);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('app.calendar'));
    }

    public function noAccess(Request $request): Response|RedirectResponse
    {
        abort_unless(ClerkLogin::enabled(), 404);
        if (Auth::check()) {
            return redirect()->route('app.calendar');
        }

        return self::page($request, 'no-access');
    }
}
