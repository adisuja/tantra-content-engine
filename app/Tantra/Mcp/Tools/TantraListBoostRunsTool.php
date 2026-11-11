<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\BoostRun;
use App\Tantra\Mcp\TantraMcp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the team engagement runs (teammate comments, reactions and the author\'s replies posted when a post goes live) for posts in the current workspace, with each action\'s status. Read only: a run can never be started from here.')]
class TantraListBoostRunsTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->workspaceOf($request);
        if ($workspace === null) {
            return Response::error('No current workspace.');
        }

        $runs = BoostRun::query()->whereHas('post', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->latest()->limit(50)->get();
        $actions = BoostAction::query()->whereIn('run_id', $runs->modelKeys())->orderBy('offset_seconds')->get()->groupBy('run_id');

        return Response::structured(['data' => $runs->map(fn (BoostRun $run): array => [
            'id' => $run->id,
            'post_id' => $run->post_id,
            'status' => $run->status,
            'window_start_at' => $run->window_start_at?->toIso8601ZuluString(),
            'actions' => collect($actions[$run->id] ?? [])->map(fn (BoostAction $a): array => [
                'kind' => $a->kind, 'by' => $a->profile_key, 'entry_index' => $a->entry_index, 'status' => $a->status,
                'error' => $a->error, 'posted_at' => $a->posted_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ])->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
