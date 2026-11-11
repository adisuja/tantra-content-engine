<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Analytics\BatchResults;
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
#[Description('Get the numbers of an engine batch\'s posts in the current workspace: impressions, unique impressions, clicks, reactions, comments, shares and our engagement rate ((reactions + comments + shares) / impressions), with roll-ups by format and pillar. A missing number is null with its reason in source_notes; personal LinkedIn posts have no impressions.')]
class TantraGetPostPerformanceTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['batch' => ['required', 'string', 'max:32']]);
        $workspace = $this->workspaceOf($request);
        if ($workspace === null) {
            return Response::error('No current workspace.');
        }

        return Response::structured(BatchResults::for((string) $workspace->account_id, $validated['batch'], [$workspace->id]));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['batch' => $schema->string()->required()->description('Engine batch number, for example "4".')];
    }
}
