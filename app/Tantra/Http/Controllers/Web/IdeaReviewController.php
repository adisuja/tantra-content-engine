<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Idea;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\IdeaToReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** tantra: "Send to review" on a Tantra idea card (PRD 25.8). Answers JSON. */
class IdeaReviewController extends Controller
{
    public function __invoke(Request $request, Idea $idea): JsonResponse
    {
        // Upstream's idea rules: a member who can edit the idea in their current workspace.
        $this->authorize('update', $idea);
        abort_unless(IdeaMeta::query()->whereKey($idea->id)->exists(), 404);

        $result = IdeaToReview::execute($request->user(), $idea);

        return response()->json([
            'message' => $result['outcome'] === 'unchanged' ? 'Already in review.' : 'Sent to review.',
            'review_url' => $result['review_url'],
            'review_item_id' => $result['review_item_id'],
        ]);
    }
}
