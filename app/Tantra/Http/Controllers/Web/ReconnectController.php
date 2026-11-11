<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** tantra: one click to the right workspace: LinkedIn's sign-in from a reminder, or the approvals tab. */
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

    /** tantra: from the Review inbox to TryPost's approvals tab, in the right workspace (PRD 10.8). */
    public function approvals(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->account_id === $workspace->account_id && $user->belongsToWorkspace($workspace), 404);

        $user->switchWorkspace($workspace);

        return redirect()->route('app.posts.index', ['tab' => 'approvals']);
    }
}
