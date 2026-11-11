<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: uploads for the engine import, into the workspace named by
 * `workspace_id` (upstream's /api/uploads only lands in the key's own workspace).
 * Post media becomes a normal workspace upload and is attached to its draft in
 * the batch call; previews are Tantra-owned files that are never pruned.
 */
class UploadController extends Controller
{
    public const MAX_KB = 97280; // 95 MB, under Cloudflare's 100 MB request cap

    public function media(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $request->validate(['media' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:image/png,image/jpeg,image/gif,image/webp,video/mp4,video/quicktime,application/pdf']]);

        $media = $workspace->addMedia($request->file('media'), Media::COLLECTION_UPLOADS);
        $media->issueUploadToken();

        return response()->json(['data' => [
            'upload_token' => $media->upload_token,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'original_filename' => $media->original_filename,
        ]], Response::HTTP_CREATED);
    }

    public function preview(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimetypes:image/png,image/jpeg,image/webp'],
            'batch' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'post' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $file = $request->file('file');
        $name = Str::lower(Str::slug(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME))).'.'.$file->extension();
        $path = $file->storeAs("tantra/previews/{$workspace->id}/{$data['batch']}/{$data['post']}", $name, ['visibility' => 'public']);

        return response()->json(['data' => ['path' => $path, 'url' => Storage::url((string) $path)]], Response::HTTP_CREATED);
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('tantra_workspace');
        abort_unless($workspace instanceof Workspace, Response::HTTP_UNPROCESSABLE_ENTITY, 'workspace_id is required.');

        return $workspace;
    }
}
