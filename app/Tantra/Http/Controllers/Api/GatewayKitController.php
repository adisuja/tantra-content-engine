<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Http\Controllers\Api\Concerns\GatewayReviewItems;
use App\Tantra\Kit\PostKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: One Content app PRD 3.8, the post kit over the signed gateway. {ref} is the review item id.
 *
 *   GET /api/gateway/tantra/posts/{ref}/thread   the kit and its etag (also the ETag header)
 *   PUT /api/gateway/tantra/posts/{ref}/thread   {first_comment?, author_reply?, entries?: [{member, text, reply_to?, excluded?}]}
 *                                                with If-Match: <etag>. 428 without it, 409 when the kit changed since (the answer
 *                                                carries the current kit), 422 code read_only once the post is approved.
 *
 * Only the keys sent are replaced; entries replaces the whole team thread. Each entry names its member by Clerk user id.
 */
class GatewayKitController extends Controller
{
    use GatewayReviewItems;

    public function show(Request $request, string $ref): JsonResponse
    {
        return $this->kit($request, $ref);
    }

    public function update(Request $request, string $ref): JsonResponse
    {
        $item = $this->item($request, $ref);
        $data = $request->validate([
            'first_comment' => ['nullable', 'string', 'max:'.PostKit::TEXT_MAX],
            'author_reply' => ['nullable', 'string', 'max:'.PostKit::TEXT_MAX],
            'entries' => ['sometimes', 'array', 'max:'.PostKit::ENTRIES_MAX],
            'entries.*.member' => ['nullable', 'string', 'max:80'],
            'entries.*.text' => ['required', 'string', 'max:'.PostKit::TEXT_MAX],
            'entries.*.reply_to' => ['nullable', 'integer', 'min:0'],
            'entries.*.excluded' => ['sometimes', 'boolean'],
        ]);

        $ifMatch = PostKit::cleanEtag($request->header('If-Match'));
        if ($ifMatch === '') {
            return response()->json(['message' => 'Send the kit etag in If-Match.', 'code' => 'etag_required'], Response::HTTP_PRECONDITION_REQUIRED);
        }
        $current = PostKit::etag($item);
        if (! hash_equals($current, $ifMatch)) {
            return response()->json(['message' => 'Updated by someone else.', 'code' => 'etag_mismatch', 'data' => PostKit::present($item)], Response::HTTP_CONFLICT)
                ->setEtag($current);
        }
        if (! PostKit::editable($item)) {
            return response()->json(['message' => 'Unapprove to edit.', 'code' => 'read_only'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entries = $request->has('entries') ? array_map(function (array $row): array {
            if (isset($row['reply_to'])) {
                $row['reply_to'] = (int) $row['reply_to'];
            }

            return $row;
        }, (array) ($data['entries'] ?? [])) : null;

        PostKit::save($request->user(), $item, [...$data, 'entries' => $entries], [
            'first_comment' => $request->exists('first_comment'),
            'author_reply' => $request->exists('author_reply'),
            'entries' => $request->has('entries'),
        ]);

        return $this->kit($request, $ref, 'Saved.');
    }

    private function kit(Request $request, string $ref, ?string $message = null): JsonResponse
    {
        $item = $this->item($request, $ref)->refresh();
        $kit = PostKit::present($item);

        return response()->json(array_filter(['message' => $message, 'data' => $kit], fn (mixed $v): bool => $v !== null))->setEtag($kit['etag']);
    }
}
