<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Manual\ManualTask;
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
#[Description('List the posts to publish by hand in the current workspace (X posts and threads, personal LinkedIn polls) that are waiting or due, with their text, thread, poll and time.')]
class TantraListManualTasksTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->workspaceOf($request);
        if ($workspace === null) {
            return Response::error('No current workspace.');
        }

        $tasks = ManualTask::query()->where('workspace_id', $workspace->id)->whereIn('status', ['waiting', 'due'])->orderBy('due_at')->limit(100)->get();

        return Response::structured(['data' => $tasks->map(fn (ManualTask $t): array => [
            'id' => $t->id, 'kind' => $t->kind, 'status' => $t->status, 'due_at' => $t->due_at?->toIso8601ZuluString(),
            'text' => $t->text, 'thread' => $t->thread, 'poll' => $t->poll, 'media_url' => $t->media_url,
        ])->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
