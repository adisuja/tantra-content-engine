<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Tantra\Channels\ChannelApps;
use App\Tantra\Channels\ConnectAccessRequested;
use App\Tantra\Channels\ConnectFailures;
use App\Tantra\Channels\PreConnect;
use App\Tantra\Notify\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the pre-connect step (PreConnect) and its "Request access" button,
 * which tells the person who looks after the developer apps whom to add.
 */
class PreConnectController extends Controller
{
    public function show(Request $request, string $network): Response
    {
        $platform = $this->platform($network);

        // Live again, or a network without an app: nothing to explain.
        if (! PreConnect::needed($platform)) {
            return redirect()->route('app.social.'.PreConnect::slug($platform).'.connect', $request->query());
        }

        return self::render($request, $platform, Response::HTTP_OK);
    }

    public static function render(Request $request, Platform $platform, int $status): Response
    {
        return Inertia::render('tantra/connect/Prepare', [
            'step' => PreConnect::step($platform, $request->user(), $request->query()),
        ])->toResponse($request)->setStatusCode($status);
    }

    public function requestAccess(Request $request, string $network): JsonResponse
    {
        $platform = $this->platform($network);
        $data = $request->validate(['handle' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $owner = PreConnect::step($platform, $user, [])['owner_name'];

        // One email per person and network an hour, however often they press it.
        if (! Cache::add("tantra:connect-access:{$user->id}:{$platform->value}", true, now()->addHour())) {
            return response()->json(['message' => __('tantra.connect.prepare.request_wait', ['owner' => $owner])]);
        }

        $manager = ChannelApps::manager();
        if ($manager !== null) {
            $app = (string) ChannelApps::appFor($platform->value);
            $network = ConnectFailures::networkName($platform);
            $ready = PreConnect::ready($platform);
            $consoleUrl = (string) (ChannelApps::catalog()[$app]['testing']['console'] ?? '');
            $handle = trim((string) ($data['handle'] ?? ''));
            $workspace = (string) ($user->currentWorkspace?->name ?? '');

            Notify::send($manager, 'connect_access_requested', [
                'network' => $platform->value,
                'app' => $app,
                'state' => $ready ? 'testing' : 'setup',
                'requester_name' => (string) $user->name,
                'requester_email' => (string) $user->email,
                'handle' => $handle,
            ], new ConnectAccessRequested(
                requesterName: (string) $user->name,
                requesterEmail: (string) $user->email,
                workspaceName: $workspace,
                network: $network,
                handle: $handle,
                ownerSteps: (string) __("tantra.connect.testing.apps.{$app}.owner"),
                ready: $ready,
                settingsUrl: PreConnect::settingsUrl($app),
                consoleUrl: $consoleUrl,
            ), $workspace !== '' ? $workspace : null, $ready && $consoleUrl !== '' ? $consoleUrl : PreConnect::settingsUrl($app), $ready ? 'Open the developer dashboard' : 'Open Channels settings');
        }

        return response()->json(['message' => __('tantra.connect.prepare.requested', ['owner' => $owner])]);
    }

    private function platform(string $network): Platform
    {
        $platform = Platform::tryFrom($network) ?? Platform::tryFrom(str_replace('-', '_', $network));
        abort_if($platform === null || $platform === Platform::Telegram || ChannelApps::appFor($platform->value) === null || ! app('router')->has('app.social.'.PreConnect::slug($platform).'.connect'), 404);

        return $platform;
    }
}
