<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Manual\ManualTasks;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** tantra: the "Post by hand" page (PRD 12): X posts, X threads, personal polls. */
class ManualTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $tasks = ManualTask::query()
            ->whereIn('workspace_id', ReviewPageController::visibleWorkspaceIds($user))
            ->where(fn ($q) => $q->whereIn('status', ['waiting', 'due'])->orWhere('updated_at', '>=', now()->subDays(7)))
            ->with(['item:id,engine_post_id,batch_id,platform,workspace_id', 'workspace:id,name'])
            ->orderByRaw("case status when 'due' then 0 when 'waiting' then 1 else 2 end")
            ->orderBy('due_at')
            ->get()
            ->map(fn (ManualTask $t): array => [
                'id' => $t->id,
                'kind' => $t->kind,
                'status' => $t->status,
                'due_at' => $t->due_at?->toIso8601String(),
                'text' => $t->text,
                'thread' => $t->thread ?? [],
                'poll' => $t->poll,
                'media_url' => $t->media_url,
                'posted_url' => $t->posted_url,
                'engine_post_id' => $t->item?->engine_post_id,
                'review_url' => $t->item ? '/tantra/review/'.$t->item->batch_id.'#'.$t->item->engine_post_id : null,
                'workspace' => $t->workspace?->name,
                'can_mark' => $t->item !== null && ReviewGate::isReviewOwner($user, $t->item),
            ]);

        return Inertia::render('tantra/tasks/Index', ['tasks' => $tasks]);
    }

    public function posted(Request $request, ManualTask $task): JsonResponse
    {
        $this->authorizeView($request, $task);
        $url = $request->validate(['url' => ['nullable', 'string', 'max:500']])['url'] ?? null;
        ManualTasks::markPosted($request->user(), $task, $url);

        return response()->json(['message' => 'Marked as posted.']);
    }

    public function skip(Request $request, ManualTask $task): JsonResponse
    {
        $this->authorizeView($request, $task);
        ManualTasks::skip($request->user(), $task);

        return response()->json(['message' => 'Skipped.']);
    }

    private function authorizeView(Request $request, ManualTask $task): void
    {
        abort_unless($task->item !== null && ReviewGate::canView($request->user(), $task->item), 404);
    }
}
