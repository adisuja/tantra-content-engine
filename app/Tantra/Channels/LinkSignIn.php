<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;

/**
 * tantra: a signed link from the Tantra app that signs the person in to the engine for one round trip (RF-10 crm-connect, the
 * Canva link for Write media). It works once, ends anyone else's engine session in this browser first and opens the team's workspace.
 */
final class LinkSignIn
{
    /** False when this signed link was opened before: a link copied out of the address bar cannot sign anyone in again. */
    public static function once(Request $request, string $kind, int $minutes): bool
    {
        return Cache::add("tantra:{$kind}:".sha1((string) $request->query('signature')), 1, now()->addMinutes($minutes + 1));
    }

    public static function signIn(Request $request, User $user, Workspace $workspace): void
    {
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
    }
}
