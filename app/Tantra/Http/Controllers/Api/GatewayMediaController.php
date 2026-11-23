<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Enums\Media\Type as MediaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Upload\CreateUploadRequest;
use App\Http\Requests\App\Post\LinkPreviewRequest;
use App\Models\Media;
use App\Models\Post;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Support\PostMediaRules;
use App\Tantra\Http\Controllers\Web\SignedMediaController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the composer's media library, upload, alt text and link preview over the signed gateway, for the pinned
 * workspace. A media `id` from here goes on post create as {"media": [{"id": "<id>"}]} (or {"upload_token": "..."}
 * for a fresh upload), like POST /api/gateway/posts.
 *
 *   GET   /api/gateway/tantra/media?type=&page=&per_page=  the workspace's media, newest first (content:read)
 *   POST  /api/gateway/tantra/media                        multipart {media: file}: a media row to use on post create (content:write)
 *   PATCH /api/gateway/tantra/media/{media}                {alt_text}: set or clear the alt text of an image (content:write)
 *   GET   /api/gateway/tantra/link-preview?url=            the title, description and image of a page (content:read)
 *
 * The link preview is the composer's own LinkCardFetcher, so its SSRF protection (SafeHttpFetcher: public hosts
 * only, no redirects to private ranges, a size cap) is the one that applies here.
 */
class GatewayMediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(array_map(fn (MediaType $t): string => $t->value, MediaType::cases()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->library($request)
            ->when($data['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (Media $m): array => $this->present($m))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(CreateUploadRequest $request): JsonResponse
    {
        $media = $request->user()->currentWorkspace->addMedia($request->file('media'), Media::COLLECTION_UPLOADS);
        $media->issueUploadToken();

        return response()->json(['data' => [
            ...$this->present($media),
            'upload_token' => $media->upload_token,
            'expires_at' => $media->created_at->copy()->addHours((int) config('trypost.media.upload_retention_hours'))->toIso8601String(),
        ]], Response::HTTP_CREATED);
    }

    public function alt(Request $request, string $media): JsonResponse
    {
        $data = $request->validate(['alt_text' => ['present', 'nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH]]);
        $row = Str::isUuid($media) ? $this->library($request)->find($media) : null;
        abort_if($row === null, Response::HTTP_NOT_FOUND);
        abort_unless($row->isImage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Alt text applies to images only.');

        $alt = filled($data['alt_text']) ? trim((string) $data['alt_text']) : null;
        $withAlt = function (?array $meta) use ($alt): ?array {
            $meta = $meta ?? [];
            if ($alt === null) {
                unset($meta['alt_text']);
            } else {
                $meta['alt_text'] = $alt;
            }

            return $meta === [] ? null : $meta;
        };
        $row->forceFill(['meta' => $withAlt($row->meta)])->save();

        // A post's own media item keeps its alt text in the post's JSON: keep that copy in step.
        if ($row->post_id !== null) {
            $post = Post::query()->where('workspace_id', $row->workspace_id)->find($row->post_id);
            if ($post !== null) {
                $post->forceFill(['media' => collect($post->media ?? [])->map(function (mixed $item) use ($row, $withAlt): mixed {
                    if (! is_array($item) || data_get($item, 'id') !== $row->id) {
                        return $item;
                    }
                    $meta = $withAlt((array) ($item['meta'] ?? []));
                    unset($item['meta']);

                    return $meta === null ? $item : [...$item, 'meta' => $meta];
                })->values()->all()])->save();
            }
        }

        return response()->json(['message' => 'Saved.', 'data' => $this->present($row->refresh())]);
    }

    public function linkPreview(LinkPreviewRequest $request, LinkCardFetcher $fetcher): JsonResponse
    {
        $card = $fetcher->fetch($request->validated('url'));

        return response()->json(['data' => $card === null ? null : [
            'url' => $card->uri,
            'domain' => $card->toArray()['domain'],
            'title' => $card->title,
            'description' => $card->description,
            'image' => $card->imageUrl,
        ]]);
    }

    /** @return Builder<Media> */
    private function library(Request $request): Builder
    {
        return Media::query()
            ->where('workspace_id', $request->user()->currentWorkspace->id)
            ->where(fn (Builder $q) => $q->whereNotNull('post_id')->orWhereNotNull('idea_id')->orWhereNotNull('rss_feed_item_id')
                ->orWhere('collection', Media::COLLECTION_UPLOADS));
    }

    /** @return array<string, mixed> */
    private function present(Media $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type->value,
            'mime_type' => $m->mime_type,
            'size' => $m->size,
            'original_filename' => $m->original_filename,
            'url' => $m->url,
            // One Content app PRD 4.1: a link that works without an engine sign in, for an hour.
            'signed_url' => SignedMediaController::url($m),
            'alt_text' => data_get($m->meta, 'alt_text'),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
