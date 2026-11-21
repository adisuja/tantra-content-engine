<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Manual\ManualTasks;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * tantra: "Post by hand" over the signed gateway (X posts, X threads, personal polls), for the pinned workspace.
 * The actions are ManualTasks::markPosted and ::skip, as api/tantra/tasks.
 *
 *   GET  /api/gateway/tantra/tasks?status=waiting,due   the tasks (default waiting and due; also posted, skipped)
 *   POST /api/gateway/tantra/tasks/{task}/posted        {url?}: mark it posted (an X or LinkedIn link)   (content:write)
 *   POST /api/gateway/tantra/tasks/{task}/skip          not posting it by hand                           (content:write)
 */
class GatewayTasksController extends Controller
{
    private const STATUSES = ['waiting', 'due', 'posted', 'skipped'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = collect(explode(',', (string) $request->query('status', 'waiting,due')))->map(fn (string $s): string => trim($s))->filter()->all();
        abort_if(array_diff($status, self::STATUSES) !== [], 422, 'Unknown status.');

        $tasks = ManualTask::query()->where('workspace_id', $user->currentWorkspace->id)
            ->whereIn('status', $status)->with('item')->orderBy('due_at')->limit(200)->get();

        return response()->json(['data' => $tasks->map(fn (ManualTask $t): array => $this->present($t, $user))->values()]);
    }

    public function posted(Request $request, string $task): JsonResponse
    {
        $row = $this->task($request, $task);
        ManualTasks::markPosted($request->user(), $row, $request->validate(['url' => ['nullable', 'string', 'max:500']])['url'] ?? null);

        return response()->json(['message' => 'Marked as posted.', 'data' => $this->present($row->refresh(), $request->user())]);
    }

    public function skip(Request $request, string $task): JsonResponse
    {
        $row = $this->task($request, $task);
        ManualTasks::skip($request->user(), $row);

        return response()->json(['message' => 'Skipped.', 'data' => $this->present($row->refresh(), $request->user())]);
    }

    private function task(Request $request, string $id): ManualTask
    {
        $user = $request->user();
        $row = Str::isUuid($id) ? ManualTask::query()->where('workspace_id', $user->currentWorkspace->id)->find($id) : null;
        abort_if($row === null || $row->item === null || ! ReviewGate::canView($user, $row->item), 404);

        return $row;
    }

    /** @return array<string, mixed> */
    private function present(ManualTask $t, User $user): array
    {
        return [
            'ref' => $t->id,
            'kind' => $t->kind,
            'status' => $t->status,
            'due_at' => $t->due_at?->toIso8601String(),
            'text' => $t->text,
            'thread' => $t->thread ?? [],
            'poll' => $t->poll,
            'media_url' => $t->media_url,
            'posted_url' => $t->posted_url,
            'review_ref' => $t->review_item_id,
            'can_mark' => $t->item !== null && ReviewGate::isReviewOwner($user, $t->item),
        ];
    }
}
