<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Idea\DeleteIdeas;
use App\Http\Controllers\Controller;
use App\Models\Idea;
use App\Models\Workspace;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\IdeaToReview;
use App\Tantra\Ideas\TantraIdeas;
use App\Tantra\Ideas\UpsertIdeas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the ideas API (PRD 25.8), for the content repo and agents. Bulk
 * upsert by external key, list with filters and `since`, edit, Send to review
 * and delete. Account owner's key only (EnsureOwnerToken).
 */
class IdeaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(TantraIdeas::filterRules());
        $page = TantraIdeas::paginate($request->user(), $filters);

        return response()->json([
            'data' => collect($page->items())->map(fn (IdeaMeta $m): array => TantraIdeas::present($m))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $rules = TantraIdeas::rules('ideas.*.');
        $rules['ideas.*.external_key'][] = 'distinct';
        $data = $request->validate([
            'ideas' => ['required', 'array', 'min:1', 'max:'.TantraIdeas::MAX_BATCH],
            'ideas.*' => ['array'],
            ...$rules,
        ]);

        /** @var Workspace|null $default */
        $default = $request->attributes->get('tantra_workspace');
        $results = UpsertIdeas::execute($request->user(), $data['ideas'], $default);

        return response()->json([
            'data' => $results,
            'counts' => collect($results)->countBy('outcome')->all(),
        ]);
    }

    public function update(Request $request, Idea $idea): JsonResponse
    {
        $meta = $this->meta($request, $idea);
        $data = $request->validate(TantraIdeas::rules(patch: true));

        return response()->json(['data' => TantraIdeas::present(UpsertIdeas::patch($request->user(), $meta, $data))]);
    }

    public function toReview(Request $request, Idea $idea): JsonResponse
    {
        $this->meta($request, $idea);

        return response()->json(['data' => IdeaToReview::execute($request->user(), $idea)]);
    }

    public function destroy(Request $request, Idea $idea): JsonResponse
    {
        $this->meta($request, $idea);
        DeleteIdeas::execute($idea->workspace, [$idea->id]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Only Tantra ideas in a workspace of this Account are found here. */
    private function meta(Request $request, Idea $idea): IdeaMeta
    {
        $meta = IdeaMeta::query()->where('account_id', $request->user()->account_id)->find($idea->id);
        abort_if($meta === null || ! TantraIdeas::workspaceIdsFor($request->user())->contains($idea->workspace_id), Response::HTTP_NOT_FOUND);

        return $meta;
    }
}
