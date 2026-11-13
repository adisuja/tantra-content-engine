<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use App\Tantra\Social\PinterestSections;
use App\Tantra\Social\YouTubeFollowUps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * tantra: live lookups the composer's format settings need: a YouTube
 * channel's playlists and a Pinterest board's sections.
 */
class FormatsController extends Controller
{
    public function youtubePlaylists(Request $request, SocialAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account, Platform::YouTube);

        return response()->json($this->lookup($account, fn () => ['playlists' => YouTubeFollowUps::playlists($account)], ['playlists' => []]));
    }

    public function pinterestSections(Request $request, SocialAccount $account, string $board): JsonResponse
    {
        $this->authorizeAccount($request, $account, Platform::Pinterest);
        abort_unless(preg_match('/^[0-9A-Za-z_-]{1,64}$/', $board) === 1, Response::HTTP_NOT_FOUND);

        return response()->json($this->lookup($account, fn () => ['sections' => PinterestSections::list($account, $board)], ['sections' => []]));
    }

    /**
     * A failed lookup answers an empty list with `failed`, so the picker says so
     * instead of erroring; publishing reports its own errors.
     *
     * @param  array<string, mixed>  $empty
     * @return array<string, mixed>
     */
    private function lookup(SocialAccount $account, callable $read, array $empty): array
    {
        try {
            if ($account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }

            return $read();
        } catch (Throwable $e) {
            report($e);

            return [...$empty, 'failed' => true];
        }
    }

    private function authorizeAccount(Request $request, SocialAccount $account, Platform $platform): void
    {
        $workspace = $request->user()->currentWorkspace;

        abort_unless(
            $workspace && $account->workspace_id === $workspace->id && $account->platform === $platform,
            Response::HTTP_FORBIDDEN,
        );

        $this->authorize('view', $workspace);
    }
}
