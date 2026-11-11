<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/**
 * tantra: the signed link in a teammate's welcome email. Before they join it
 * opens the set-password page; afterwards it is just a way to the login page.
 */
class JoinController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        if (ReviewGate::hasJoined($user)) {
            return redirect()->route(Auth::check() ? 'tantra.review.index' : 'login');
        }

        Auth::logout();

        return redirect()->route('password.reset', ['token' => Password::createToken($user), 'email' => $user->email]);
    }
}
