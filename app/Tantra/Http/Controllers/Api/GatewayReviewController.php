<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Http\Controllers\Api\Concerns\GatewayReviewItems;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Models\ReviewEvent;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: the rest of Content > Review over the signed gateway: the comment thread, a note back to the writer,
 * numbers to sign off and taking an approval back. The actions are the ones api/tantra/review/items/* run for a
 * user token (ReviewActions), so the rules, the audit events and the "review owner only" checks are the same.
 *
 *   GET  /api/gateway/tantra/review/{item}/comments        the comments and change requests, and the numbers to sign off
 *   POST /api/gateway/tantra/review/{item}/comments        {body}: add a comment                       (content:write)
 *   POST /api/gateway/tantra/review/{item}/request-changes {note}: send it back with a note            (content:write)
 *   POST /api/gateway/tantra/review/{item}/sign-off        {index}: sign off one number                (content:publish)
 *   POST /api/gateway/tantra/review/{item}/unapprove       take an approval back to review             (content:publish)
 *
 * Every write answers like the review list: {message, data: <the post card>}.
 */
class GatewayReviewController extends Controller
{
    use GatewayReviewItems;

    public function comments(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        $user = $request->user();

        $comments = $row->events()->with('user:id,name')->get()
            ->filter(fn (ReviewEvent $e): bool => in_array($e->kind, [EventKind::Comment, EventKind::RequestChanges], true))
            ->map(fn (ReviewEvent $e): array => [
                'kind' => $e->kind->value,
                'body' => (string) $e->body,
                'by' => $e->user?->name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values();

        $numbers = collect($row->numbers_for_signoff ?? [])->values()->map(fn (array $n, int $k): array => [
            'index' => $k,
            'text' => (string) ($n['text'] ?? ''),
            'signed' => filled($n['signed_at'] ?? null),
            'can_sign' => blank($n['signed_at'] ?? null) && ReviewGate::canSign($user, $row, (string) ($n['signer'] ?? '')),
        ]);

        return response()->json(['data' => ['comments' => $comments, 'numbers_for_signoff' => $numbers]]);
    }

    public function comment(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        ReviewActions::comment($request->user(), $row, $request->validate(['body' => ['required', 'string', 'max:5000']])['body']);

        return $this->ok($request, $row, 'Comment saved.');
    }

    public function requestChanges(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        ReviewActions::requestChanges($request->user(), $row, $request->validate(['note' => ['required', 'string', 'max:2000']])['note']);

        return $this->ok($request, $row, 'Changes requested.');
    }

    public function signOff(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        ReviewActions::signOff($request->user(), $row, (int) $request->validate(['index' => ['required', 'integer', 'min:0']])['index']);

        return $this->ok($request, $row, 'Signed off.');
    }

    public function unapprove(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        ReviewActions::unapprove($request->user(), $row);

        return $this->ok($request, $row, 'Back in review.');
    }
}
