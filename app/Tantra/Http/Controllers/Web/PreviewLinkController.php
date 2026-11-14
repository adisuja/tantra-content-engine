<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * tantra: the public preview of one post's asset, /previewlink/{token}. No sign in:
 * the token is long and random, and the page shows only what the post gives away
 * (the resource's pages and links) or, without a resource, the post's own images.
 * Teammates open it from the review screen; follow-ups can send it too.
 */
class PreviewLinkController extends Controller
{
    public function __invoke(string $token): View
    {
        abort_unless(strlen($token) >= 32 && strlen($token) <= 64, 404);
        $item = ReviewItem::query()->where('preview_token', $token)->firstOrFail();

        $manifest = (array) ($item->media_manifest ?? []);
        $resource = (array) data_get($item->payload, 'resource', []);
        $resourcePages = collect((array) ($manifest['resource_previews'] ?? []))->filter(fn ($p): bool => is_string($p))->map(fn (string $p): string => Storage::url($p))->values();
        $postPages = collect((array) ($manifest['previews'] ?? []))->filter(fn ($p): bool => is_string($p))->map(fn (string $p): string => Storage::url($p))->values();
        $hasResource = filled($resource['name'] ?? null);

        return view('tantra.previewlink', [
            'title' => $hasResource ? (string) $resource['name'] : ((string) ($manifest['title'] ?? '') ?: 'Preview'),
            'what' => $hasResource ? (string) ($resource['what'] ?? '') : null,
            'pages' => $hasResource && $resourcePages->isNotEmpty() ? $resourcePages : $postPages,
            'pageUrl' => $hasResource ? ($resource['url'] ?? null) : null,
            'files' => collect((array) ($resource['files'] ?? []))
                ->filter(fn ($f): bool => is_array($f) && filled($f['href'] ?? null) && str_starts_with((string) $f['href'], 'https://'))
                ->map(fn (array $f): array => ['label' => (string) ($f['label'] ?? 'Download'), 'href' => (string) $f['href']])
                ->values(),
        ]);
    }
}
