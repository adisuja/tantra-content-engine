<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tantra\Repurpose\Reposts;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: Repost (PRD 25.10): the channels a post can go to, and sending it
 * there. One agent job writes every version; each lands in Review for its
 * channel, or in Ideas when the network is not connected yet.
 */
class RepostController extends Controller
{
    public function targets(Request $request): JsonResponse
    {
        return response()->json(['data' => Reposts::targets($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'review_item_id' => ['required', 'uuid'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*.channel_id' => ['nullable', 'uuid'],
            'targets.*.network' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $source = ReviewItem::query()->where('account_id', $request->user()->account_id)->findOrFail($data['review_item_id']);
        $job = Reposts::start($request->user(), $source, $data['targets'], $data['note'] ?? null);
        $outcome = $job->toOutcome();

        return response()->json([
            'message' => $outcome['status'] === 'waiting'
                ? 'Queued. The team writes the new versions next; they show in Review and Ideas when ready.'
                : 'Writing the new versions now. They show in Review (and in Ideas for networks not connected yet).',
            'data' => $outcome,
        ], 201);
    }
}
