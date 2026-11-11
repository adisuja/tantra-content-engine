<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Giveaway\GiveawayReply;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** tantra: review actions for the studio screens. Answers JSON; the screen says "Saved" only on success. */
class ReviewItemActionController extends Controller
{
    public function approve(Request $request, ReviewItem $item): JsonResponse
    {
        $this->authorizeView($request, $item);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return $this->ok($item, ReviewActions::approve($request->user(), $item, $note)['message']);
    }

    public function unapprove(Request $request, ReviewItem $item): JsonResponse
    {
        $this->authorizeView($request, $item);
        ReviewActions::unapprove($request->user(), $item);

        return $this->ok($item, 'Back in review.');
    }

    public function requestChanges(Request $request, ReviewItem $item): JsonResponse
    {
        $this->authorizeView($request, $item);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        ReviewActions::requestChanges($request->user(), $item, $note);

        return $this->ok($item, 'Changes requested.');
    }

    public function reject(Request $request, ReviewItem $item): JsonResponse
    {
        $this->authorizeView($request, $item);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;
        ReviewActions::reject($request->user(), $item, $note);

        return $this->ok($item, 'Rejected.');
    }

    public function comment(Request $request, ReviewItem $item): JsonResponse
    {
        $this->authorizeView($request, $item);
        $body = $request->validate(['body' => ['required', 'string', 'max:5000']])['body'];
        ReviewActions::comment($request->user(), $item, $body);

        return $this->ok($item, 'Comment saved.');
    }

    public function signOff(Request $request, ReviewItem $item): JsonResponse
    {
        $this->authorizeView($request, $item);
        $index = (int) $request->validate(['index' => ['required', 'integer', 'min:0']])['index'];
        ReviewActions::signOff($request->user(), $item, $index);

        return $this->ok($item, 'Signed off.');
    }

    /** tantra: tick a commenter off the "DMs to send" list once the resource is sent. */
    public function dmSent(Request $request, ReviewItem $item, GiveawayReply $reply): JsonResponse
    {
        $this->authorizeView($request, $item);
        abort_unless($reply->review_item_id === $item->id, 404);
        $sent = (bool) $request->validate(['sent' => ['required', 'boolean']])['sent'];
        $reply->forceFill(['dm_sent_at' => $sent ? now() : null])->save();

        return $this->ok($item, $sent ? 'Marked as sent.' : 'Marked as not sent.');
    }

    private function authorizeView(Request $request, ReviewItem $item): void
    {
        abort_unless(ReviewGate::canView($request->user(), $item), 404);
    }

    private function ok(ReviewItem $item, string $message): JsonResponse
    {
        $item->refresh();

        return response()->json([
            'message' => $message,
            'item' => [
                'id' => $item->id,
                'review_status' => $item->review_status->value,
                'slot_fallback' => $item->slot_fallback,
                'numbers_for_signoff' => $item->numbers_for_signoff,
                'revision' => $item->revision,
            ],
        ]);
    }
}
