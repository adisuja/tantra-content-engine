<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Idea\DeleteIdeas;
use App\Http\Controllers\Controller;
use App\Models\Idea;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\IdeaToReview;
use App\Tantra\Ideas\TantraIdeas;
use App\Tantra\Ideas\UpsertIdeas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the Tantra ideas (api/tantra/ideas: a network, a phone preview and a path to Review) over the signed
 * gateway, for the pinned workspace only. The actions are TantraIdeas, UpsertIdeas and IdeaToReview, as the API.
 * The engine's own ideas board is the separate /api/gateway/ideas.
 *
 *   GET    /api/gateway/tantra/ideas?kind=&state=&platform=&stage=&since=&page=&per_page=   (content:read)
 *   POST   /api/gateway/tantra/ideas                  one idea {external_key, platform, title?, body?, hook?, format?, preview?, ...}:
 *                                                     a new key creates, a known key updates (201 or 200)   (content:write)
 *   PATCH  /api/gateway/tantra/ideas/{idea}           the same fields, all optional                          (content:write)
 *   DELETE /api/gateway/tantra/ideas/{idea}                                                                  (content:write)
 *   POST   /api/gateway/tantra/ideas/{idea}/to-review send it to Review (the same item when sent twice)      (content:write)
 *
 * An idea is named by `ref`. The workspace, channel, source post and agent job ids the API shows are left out.
 */
class GatewayIdeasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(TantraIdeas::filterRules());
        $filters['workspace_id'] = $request->user()->currentWorkspace->id;
        unset($filters['profile']);
        $page = TantraIdeas::paginate($request->user(), $filters);

        return response()->json([
            'data' => collect($page->items())->map(fn (IdeaMeta $m): array => $this->present($m))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rules = TantraIdeas::rules();
        unset($rules['workspace_id'], $rules['profile']);
        $data = $request->validate($rules);

        $user = $request->user();
        $result = UpsertIdeas::execute($user, [$data], $user->currentWorkspace)[0];

        return response()->json([
            'outcome' => $result['outcome'],
            'reason' => $result['reason'],
            'data' => $this->shape($result['idea']),
        ], $result['outcome'] === 'created' ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function update(Request $request, string $idea): JsonResponse
    {
        $meta = $this->meta($request, $idea);
        $data = $request->validate(TantraIdeas::rules(patch: true));

        return response()->json(['data' => $this->present(UpsertIdeas::patch($request->user(), $meta, $data))]);
    }

    public function destroy(Request $request, string $idea): JsonResponse
    {
        $meta = $this->meta($request, $idea);
        $row = Idea::query()->findOrFail($meta->idea_id);
        DeleteIdeas::execute($row->workspace, [$row->id]);

        return response()->json(['message' => 'Deleted.']);
    }

    public function toReview(Request $request, string $idea): JsonResponse
    {
        $meta = $this->meta($request, $idea);
        $result = IdeaToReview::execute($request->user(), Idea::query()->findOrFail($meta->idea_id));

        return response()->json(['outcome' => $result['outcome'], 'review_ref' => $result['review_item_id'], 'data' => $this->shape($result['idea'])]);
    }

    /** Only a Tantra idea of the pinned workspace is found here. */
    private function meta(Request $request, string $id): IdeaMeta
    {
        $workspace = $request->user()->currentWorkspace;
        $meta = Str::isUuid($id) ? IdeaMeta::query()->where('account_id', $workspace->account_id)->with('idea')->find($id) : null;
        abort_if($meta === null || $meta->idea === null || $meta->idea->workspace_id !== $workspace->id, Response::HTTP_NOT_FOUND);

        return $meta;
    }

    /** @return array<string, mixed> */
    private function present(IdeaMeta $meta): array
    {
        return $this->shape(TantraIdeas::present($meta));
    }

    /**
     * The API's idea without the ids the gateway keeps to itself.
     *
     * @param  array<string, mixed>  $idea
     * @return array<string, mixed>
     */
    private function shape(array $idea): array
    {
        $idea['ref'] = $idea['id'];
        $idea['stage'] = $idea['stage']['name'] ?? null;
        $idea['review_ref'] = $idea['review_item_id'];

        return array_diff_key($idea, array_flip(['id', 'workspace_id', 'channel_id', 'source_review_item_id', 'source_post_id', 'agent_job_id', 'review_item_id', 'review_url']));
    }
}
