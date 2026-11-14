<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Models\Idea;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\Networks;
use App\Tantra\Ideas\TantraIdeas;
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
#[Description('List Tantra ideas: ideas with a network, a phone preview, a kind (new, repurpose, repost) and a state (idea, in_review, dropped). The Account owner sees every workspace of the Account, anyone else their current workspace. Filter by workspace_id, profile, kind, state, platform, batch (the "Batch N" stage), stage name, external_key, or since (changed at or after an ISO 8601 time).')]
class TantraListIdeasTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $filters = $request->validate(TantraIdeas::filterRules());
        if ($request->user()?->currentWorkspace === null || ! $request->user()->can('viewAny', Idea::class)) {
            return Response::error('You cannot see ideas in this workspace.');
        }

        $page = TantraIdeas::paginate($request->user(), $filters);

        return Response::structured([
            'data' => collect($page->items())->map(fn (IdeaMeta $m): array => TantraIdeas::present($m))->values()->all(),
            'page' => $page->currentPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('Only ideas in this workspace.'),
            'profile' => $schema->string()->description('Engine profile key, for example "ben".'),
            'kind' => $schema->string()->enum(IdeaMeta::KINDS)->description('new, repurpose or repost.'),
            'state' => $schema->string()->enum(IdeaMeta::STATES)->description('idea, in_review or dropped.'),
            'platform' => $schema->string()->enum(Networks::keys())->description('The network the idea is for.'),
            'batch' => $schema->string()->description('Batch number: ideas in the stage "Batch N".'),
            'stage' => $schema->string()->description('Ideas in the stage with this name, for example "Repurposed".'),
            'external_key' => $schema->string()->description('One idea by its external key.'),
            'since' => $schema->string()->description('Only ideas changed at or after this ISO 8601 time.'),
            'per_page' => $schema->integer()->description('Up to 500, 100 by default.'),
            'page' => $schema->integer()->description('Page number, from 1.'),
        ];
    }
}
