<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\Media\CanvaPreset;
use App\Enums\Media\Source;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Integration\CreateCanvaDesignRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Channels\LinkSignIn;
use App\Tantra\Media\CanvaLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * tantra: One Content app PRD 4.1, GET tantra/canva/start, the signed link the gateway hands the CRM's Write media picker (CanvaLink).
 * Once and for ten minutes: it signs the person in, keeps the CRM return URL for this nonce and opens the engine's own Canva route.
 * A used link, or a person who may no longer make posts there, goes straight back with canva=failed.
 */
class CanvaStartController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $return = CanvaLink::safeReturn($request->query('return'));
        $nonce = (string) $request->query('nonce');
        $preset = CanvaPreset::tryFrom((string) $request->query('preset'));
        abort_if($return === null || $preset === null || preg_match(CreateCanvaDesignRequest::NONCE_PATTERN, $nonce) !== 1, 404);
        $fail = fn (): RedirectResponse => redirect()->away(CanvaLink::withResult($return, $nonce, CanvaLink::FAILED));

        if (! LinkSignIn::once($request, 'canva-start', CanvaLink::TTL_MINUTES) || ! Source::Canva->isEnabled()) {
            return $fail();
        }

        $user = User::query()->find($request->query('u'));
        $workspace = Workspace::query()->find($request->query('w'));
        if (! $user instanceof User || ! $workspace instanceof Workspace || ! $user->can('createPost', $workspace)) {
            return $fail();
        }

        LinkSignIn::signIn($request, $user, $workspace);
        CanvaLink::remember($nonce, $return);

        return redirect()->route('app.integrations.canva.designs.create', ['preset' => $preset->value, 'nonce' => $nonce]);
    }
}
