<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Media\Type as MediaType;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Tantra\Http\Controllers\Web\SignedMediaController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Serves an image from the app's own origin, so the media editor can draw it
 * on a canvas and export the result: a file served from the storage host
 * without CORS headers would taint the canvas. Only the image types uploads
 * accept are served, never a video, a PDF or anything a browser would run.
 */
class MediaFileController extends Controller
{
    public function show(Request $request, Media $media): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('createPost', $workspace);
        abort_unless($media->workspace_id === $workspace->id, 404);
        abort_unless(MediaType::fromMime((string) $media->mime_type) === MediaType::Image, 404);
        abort_unless(Storage::exists($media->path), 404);

        // tantra: One Content app PRD 4.1, the file itself is served by its signed link (same origin, so a canvas stays clean).
        return redirect()->to(SignedMediaController::url($media));
    }
}
