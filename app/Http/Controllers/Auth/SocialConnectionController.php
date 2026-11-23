<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FinishSocialConnectionRequest;
use App\Tantra\Channels\AccountPicker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The confirmation page every redirect-based connection lands on, handed to
 * the controller of the network being connected.
 */
class SocialConnectionController extends Controller
{
    public function show(Request $request, Platform $platform): Response|SymfonyResponse
    {
        // tantra: One Content app PRD 4.1, the CRM's Content > Channels picks the accounts (AccountPicker).
        if (AccountPicker::enabled($platform) && $request->user() !== null) {
            return Inertia::location(AccountPicker::handoff($request->user(), $platform));
        }

        return $this->network($platform)->confirm($request);
    }

    public function store(FinishSocialConnectionRequest $request, Platform $platform): RedirectResponse
    {
        return $this->network($platform)->finish($request);
    }

    private function network(Platform $platform): SocialController
    {
        return self::networkFor($platform);
    }

    /** The controller of the network being connected (also used by the CRM's account picker, AccountPicker). */
    public static function networkFor(Platform $platform): SocialController
    {
        return app(match ($platform) {
            Platform::LinkedIn => LinkedInController::class,
            Platform::X => XController::class,
            Platform::TikTok => TikTokController::class,
            Platform::YouTube => YouTubeController::class,
            Platform::Facebook => FacebookController::class,
            Platform::Instagram => InstagramController::class,
            Platform::InstagramFacebook => InstagramFacebookController::class,
            Platform::Threads => ThreadsController::class,
            Platform::Pinterest => PinterestController::class,
            Platform::Bluesky => BlueskyController::class,
            Platform::Mastodon => MastodonController::class,
            Platform::Discord => DiscordController::class,
            Platform::GoogleBusiness => GoogleBusinessController::class,
            // tantra: Zernio channels are linked with tantra:zernio-connect, never through OAuth.
            Platform::LinkedInPage, Platform::Telegram, Platform::ZernioLinkedInPage, Platform::UnipileLinkedIn => abort(SymfonyResponse::HTTP_NOT_FOUND),
        });
    }
}
