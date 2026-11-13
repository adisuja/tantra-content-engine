<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\Auth\LoginRequest;
use App\Jobs\PostHog\SyncUser;
use App\Models\Invite;
use App\Services\PostHogService;
use App\Tantra\Auth\ClerkLogin;
use App\Tantra\Http\Controllers\Web\ClerkSessionController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        // tantra: the usetantra.com login (Clerk); ?password=1 keeps this form.
        if (ClerkLogin::enabled() && ! $request->boolean('password')) {
            return ClerkSessionController::page($request);
        }

        return Inertia::render('auth/Login', [
            'status' => session('status'),
            'email' => $request->query('email'),
            'invite' => $request->query('invite'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if ($locale = $request->validated('locale')) {
            $user = $request->user();
            $user->update(['locale' => $locale]);

            if (PostHogService::shouldTrack()) {
                SyncUser::dispatch((string) $user->id);
            }
        }

        if ($invite = Invite::fromId($request->string('invite')->toString())) {
            return redirect()->route('app.invites.show', $invite);
        }

        return redirect()->intended(route('app.calendar'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // tantra: end the Clerk session too, or the sign in page would sign straight back in.
        if (ClerkLogin::enabled()) {
            $request->session()->flash(ClerkSessionController::SIGNED_OUT, true);

            return redirect()->route('login');
        }

        return redirect()->route('app.home');
    }
}
