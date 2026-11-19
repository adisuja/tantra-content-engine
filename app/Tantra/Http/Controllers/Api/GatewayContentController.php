<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Tantra\Create\CreateFromFormat;
use App\Tantra\Create\FormatCards;
use App\Tantra\Create\ImportBuilt;
use App\Tantra\Create\ReviewFeed;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * tantra: Tantra's native Content > Create and Content > Review over the signed gateway (AF-09, PRD 9.22; D17: the
 * Tantra app, chat and API reach the same routes). AuthenticateTantraGateway has already made the request the
 * member's engine user in the pinned (else current) workspace: reads need content:read, create and edit
 * content:write, approve and reject content:publish.
 *
 *   GET  /api/gateway/tantra/formats                     the format cards
 *   POST /api/gateway/tantra/create                      {format, idea, platform?}: write a piece and push it to Review
 *                                                        {format, idea?, platform?, built}: a piece the CRM built, into Review as it is (201)
 *   GET  /api/gateway/tantra/create                      the recent pieces of this workspace
 *   GET  /api/gateway/tantra/create/{piece}              one piece: creating, ready or failed
 *   GET  /api/gateway/tantra/review?state=               the posts to review (waiting, approved, published, rejected)
 *   PUT  /api/gateway/tantra/review/{item}               {text}: edit a post that waits for review
 *                                                        {text?, built}: replace the copy and media of a post made in Create
 *   POST /api/gateway/tantra/review/{item}/approve       approve it (the studio's own approval and slot rules)
 *   POST /api/gateway/tantra/review/{item}/reject        reject it
 */
class GatewayContentController extends Controller
{
    public function formats(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => FormatCards::for($user),
            'blocker' => CreateFromFormat::blocker($user->currentWorkspace),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        if ($request->input('built') !== null) {
            return $this->createBuilt($request);
        }
        $data = $request->validate([
            'format' => ['required', 'string', 'max:16'],
            'idea' => ['required', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', Rule::in(CreateFromFormat::PLATFORMS)],
        ]);
        $user = $request->user();
        $state = CreateFromFormat::start($user, $user->currentWorkspace, $data['format'], $data['idea'], $data['platform'] ?? null);

        return response()->json(['message' => 'Creating. It shows in Review as soon as it is written.', 'data' => CreateFromFormat::present($state)], 202);
    }

    /** CR-06: the CRM built the piece itself (copy and media); it goes into Review as it is, with no writing step. */
    private function createBuilt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'max:16'],
            'idea' => ['nullable', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', Rule::in(CreateFromFormat::PLATFORMS)],
            ...ImportBuilt::rules(),
            'built' => ['required', 'array'],
        ]);
        $user = $request->user();
        $state = ImportBuilt::create($user, $user->currentWorkspace, $data['format'], $data['idea'] ?? null, $data['platform'] ?? null, $data['built']);

        return response()->json(['message' => 'In Review.', 'data' => CreateFromFormat::present($state)], 201);
    }

    public function pieces(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $s): array => CreateFromFormat::present($s), CreateFromFormat::recent($request->user()->currentWorkspace->id))]);
    }

    public function piece(Request $request, string $piece): JsonResponse
    {
        $state = CreateFromFormat::state($piece);
        abort_if($state === null || $state['workspace_id'] !== $request->user()->currentWorkspace->id, 404);

        return response()->json(['data' => CreateFromFormat::present($state)]);
    }

    public function review(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['nullable', Rule::in(array_keys(ReviewFeed::STATES))], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);

        return response()->json(['data' => ReviewFeed::for($request->user(), $data['state'] ?? 'waiting', (int) ($data['limit'] ?? 60))]);
    }

    public function edit(Request $request, string $item): JsonResponse
    {
        if ($request->input('built') !== null) {
            $data = $request->validate(['text' => ['nullable', 'string', 'max:'.ReviewFeed::TEXT_MAX], ...ImportBuilt::rules(), 'built' => ['required', 'array']]);
            $row = $this->item($request, $item);
            ImportBuilt::edit($request->user(), $row, [...$data['built'], 'text' => $data['built']['text'] ?? $data['text'] ?? '']);

            return $this->ok($request, $row, 'Saved.');
        }
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:'.ReviewFeed::TEXT_MAX]])['text'];
        $row = $this->item($request, $item);
        ReviewFeed::editText($request->user(), $row, $text);

        return $this->ok($request, $row, 'Saved.');
    }

    public function approve(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        $result = ReviewActions::approve($request->user(), $row, $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null, confirmNumbers: true);

        return $this->ok($request, $row, (string) $result['message']);
    }

    public function reject(Request $request, string $item): JsonResponse
    {
        $row = $this->item($request, $item);
        ReviewActions::reject($request->user(), $row, $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return $this->ok($request, $row, 'Rejected.');
    }

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
