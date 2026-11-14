<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Models\Idea;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\IdeaToReview;
use App\Tantra\Ideas\TantraIdeas;
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
#[Description('Send a Tantra idea to review: it becomes a review item for its workspace\'s review owner, with a draft on the idea\'s channel, and approval places it in that channel\'s slots. X ideas are posted by hand at their planned time. Needs a connected channel for the network (X excepted). Sending twice returns the same review item.')]
class TantraIdeaToReviewTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['idea_id' => ['required', 'uuid']]);
        $user = $request->user();
        $meta = IdeaMeta::query()->where('account_id', $user?->account_id)->find($validated['idea_id']);
        $idea = $meta?->idea;

        if ($idea === null || ! TantraIdeas::workspaceIdsFor($user)->contains($idea->workspace_id) || ! $user->can('create', Idea::class)) {
            return Response::error('Idea not found.');
        }

        $result = null;
        if ($error = $this->attempt(function () use ($user, $idea, &$result): void {
            $result = IdeaToReview::execute($user, $idea);
        })) {
            return $error;
        }

        return Response::structured($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_id' => $schema->string()->required()->description('UUID of the idea (id in tantra-list-ideas-tool).'),
        ];
    }
}
