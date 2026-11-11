<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** tantra: one click from the reminder to LinkedIn's sign-in, in the right workspace. */
class ReconnectController extends Controller
{
    public function __invoke(Request $request, SocialAccount $account): RedirectResponse
    {
        $user = $request->user();
        $workspace = $account->workspace;
        abort_unless($workspace !== null && $user->account_id === $workspace->account_id && $user->belongsToWorkspace($workspace), 404);

        $user->switchWorkspace($workspace);

        return redirect()->route('app.social.linkedin.connect');
    }
}
