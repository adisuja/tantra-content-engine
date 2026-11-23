<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\Media\Type as MediaType;
use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * tantra: One Content app PRD 4.1. A media file by a signed, expiring link, with no engine sign in: the gateway hands these to the CRM
 * (GET /api/gateway/tantra/media), and the old /media/{media}/file route (behind the engine login) sends its visitors here. Images,
 * videos and documents are served with a sandbox policy and nosniff, so nothing in them runs.
 */
class SignedMediaController extends Controller
{
    public const int TTL_MINUTES = 60;

    public static function url(Media $media, int $minutes = self::TTL_MINUTES): string
    {
        return URL::temporarySignedRoute('tantra.media.signed', now()->addMinutes($minutes), ['media' => $media->id]);
    }

    public function __invoke(string $media): StreamedResponse
    {
        $row = Media::query()->find($media);
        abort_if($row === null || blank($row->path) || ! Storage::exists($row->path), 404);
        $type = MediaType::fromMime((string) $row->mime_type);
        $inline = in_array($type, [MediaType::Image, MediaType::Video], true);

        return Storage::response($row->path, $row->original_filename ?: basename((string) $row->path), [
            'Content-Type' => $row->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
