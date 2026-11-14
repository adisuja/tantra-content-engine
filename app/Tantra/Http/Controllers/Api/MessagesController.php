<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Messages;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: the follow-up messages of one post, for the workflows that send its
 * resource by email, WhatsApp, SMS or LinkedIn (the CRM's engagement follow-ups,
 * agents). Always the review owner's edited text when there is one.
 *
 * GET /api/tantra/messages/{engineBatchId}/{enginePostId}?first_name=Sam
 */
class MessagesController extends Controller
{
    public function show(Request $request, string $engineBatchId, string $enginePostId): JsonResponse
    {
        $data = $request->validate(['first_name' => ['nullable', 'string', 'max:80']]);

        $item = ReviewItem::query()
            ->where('account_id', $request->user()->account_id)
            ->where('engine_batch_id', $engineBatchId)
            ->where('engine_post_id', $enginePostId)
            ->where('review_status', '!=', ReviewStatus::Superseded->value)
            ->latest('updated_at')
            ->firstOrFail();

        $postUrl = $item->posts()->pluck('platform_url')->filter()->first();
        $firstName = (string) ($data['first_name'] ?? '');

        return response()->json(['data' => [
            'engine_batch_id' => $item->engine_batch_id,
            'engine_post_id' => $item->engine_post_id,
            'review_status' => $item->review_status->value,
            'resource' => data_get($item->payload, 'resource'),
            'preview_url' => $item->previewUrl(),
            'review_url' => $item->shareUrl(),
            'post_url' => $postUrl,
            'messages' => collect(Messages::for($item))->map(fn (array $m, string $key): array => [
                'label' => $m['label'],
                'edited' => $m['edited'],
                'text' => $m['text'],
                'rendered' => $firstName !== '' || $postUrl !== null ? Messages::render($item, $key, $firstName, $postUrl) : null,
            ])->all(),
        ]]);
    }
}
