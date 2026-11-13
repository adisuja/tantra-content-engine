<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** tantra: a channel from another workspace in the sidebar: switch to its workspace and open it (PRD D1). */
class OpenChannelController extends Controller
{
    public function __invoke(Request $request, SocialAccount $account): RedirectResponse
    {
        $user = $request->user();
        $workspace = $account->workspace;
        abort_if($workspace === null || ! $user->belongsToWorkspace($workspace), 403);

        if ($user->current_workspace_id !== $workspace->id) {
            $user->switchWorkspace($workspace);
        }

        return redirect()->route('app.channels.publish', $account);
    }
}
