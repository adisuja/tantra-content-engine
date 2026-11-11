<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Manual\ManualTask;
use App\Tantra\Manual\ManualTasks;
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

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Mark a post-by-hand task as posted, with the link of the post you published. This only records it; it posts nothing anywhere.')]
class TantraMarkManualTaskPostedTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['task_id' => ['required', 'uuid'], 'url' => ['nullable', 'url', 'max:2000']]);
        $workspace = $this->workspaceOf($request);
        $task = $workspace ? ManualTask::query()->where('workspace_id', $workspace->id)->find($validated['task_id']) : null;
        if ($task === null) {
            return Response::error('Task not found.');
        }

        if ($error = $this->attempt(fn () => ManualTasks::markPosted($request->user(), $task, $validated['url'] ?? null))) {
            return $error;
        }

        return Response::structured(['id' => $task->id, 'status' => $task->refresh()->status, 'posted_url' => $task->posted_url]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()->required()->description('UUID of the task.'),
            'url' => $schema->string()->description('The link of the post you published.'),
        ];
    }
}
