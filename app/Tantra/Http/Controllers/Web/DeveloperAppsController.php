<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Tantra\Channels\ChannelApps;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * tantra: Settings > Admin > Developer apps (Adi 2026-11-14). The developer
 * material behind each network's Connect button: app keys, portals, redirect
 * URLs and catches, plus the Tantra app's linked accounts. Only the instance
 * owner opens it; everyone else connects from the logo grid and never sees it.
 */
class DeveloperAppsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(ChannelApps::canManage($user), HttpResponse::HTTP_FORBIDDEN);

        $linked = SocialAccount::query()
            ->whereHas('workspace', fn ($q) => $q->where('account_id', $user->account_id))
            ->where('platform', Platform::UnipileLinkedIn)
            ->get(['status']);

        return Inertia::render('tantra/developer-apps/Index', [
            'channelApps' => ChannelAppController::present(true),
            'linkedAccounts' => [
                'total' => $linked->count(),
                'connected' => $linked->filter(fn ($c): bool => $c->status === Status::Connected)->count(),
            ],
        ]);
    }
}
