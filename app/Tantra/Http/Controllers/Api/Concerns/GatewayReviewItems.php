<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api\Concerns;

use App\Models\Post;
use App\Tantra\Create\ReviewFeed;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * tantra: the review item lookup and the answer shape every gateway review route shares. The item is found only
 * among the acting member's visible workspaces, and the answer is the same card the review list returns (ref, no
 * batch or engine ids).
 */
trait GatewayReviewItems
{
    private function item(Request $request, string $id): ReviewItem
    {
        $user = $request->user();
        $row = Str::isUuid($id)
            ? ReviewItem::query()->where('account_id', $user->account_id)->whereIn('workspace_id', ReviewPageController::visibleWorkspaceIds($user))->find($id)
            : null;
        abort_if($row === null || ! ReviewGate::canView($user, $row), 404);

        return $row;
    }

    private function ok(Request $request, ReviewItem $item, string $message): JsonResponse
    {
        $item->refresh()->load('workspace');
        $posts = $item->post_group_id ? Post::query()->where('post_group_id', $item->post_group_id)->get() : collect();

        return response()->json(['message' => $message, 'data' => ReviewFeed::item($item, $request->user(), $posts)]);
    }
}
