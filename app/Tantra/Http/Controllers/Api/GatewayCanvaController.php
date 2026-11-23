<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Enums\Media\CanvaPreset;
use App\Enums\Media\Source;
use App\Http\Controllers\Controller;
use App\Tantra\Media\CanvaLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: One Content app PRD 4.1, "Design in Canva" for the CRM's Write media picker (CanvaLink).
 *
 *   GET /api/gateway/tantra/canva/start?return=<CRM url>&preset=<size>
 *       answers {url, nonce, expires_in}: a signed link to open in a new window. It signs the person in to the engine once and opens
 *       Canva at that size (square by default). Back from Canva, the window lands on the return URL with
 *       ?canva=done&nonce=<nonce>&import=<id> (or canva=failed); GET /api/gateway/tantra/media/imports/{id} then reads the export.
 */
class GatewayCanvaController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'return' => ['required', 'string', 'max:1000'],
            'preset' => ['nullable', 'string', Rule::enum(CanvaPreset::class)],
        ]);
        $return = CanvaLink::safeReturn($data['return']);
        if ($return === null) {
            return response()->json(['message' => 'The return address must be a Tantra Content page.', 'code' => 'bad_return'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        abort_unless(Source::Canva->isEnabled(), Response::HTTP_NOT_FOUND, 'Canva is not set up on this engine.');

        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $this->authorize('createPost', $workspace);

        $nonce = Str::random(32);
        $preset = CanvaPreset::tryFrom((string) ($data['preset'] ?? '')) ?? CanvaPreset::Square;

        return response()->json(['data' => [
            'url' => CanvaLink::signedUrl($user, $workspace, $preset, $nonce, $return),
            'nonce' => $nonce,
            'preset' => $preset->value,
            'expires_in' => CanvaLink::TTL_MINUTES * 60,
        ]]);
    }
}
