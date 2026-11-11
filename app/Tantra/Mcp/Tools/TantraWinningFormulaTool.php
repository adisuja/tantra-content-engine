<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Analytics\Insights;
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
#[Description('Get the winning formula for the current workspace\'s person: the formats and samples that beat their own median engagement over their last 3 batches, with how many posts each rests on and a weak_signal flag while the sample is small.')]
class TantraWinningFormulaTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->workspaceOf($request);
        if ($workspace === null) {
            return Response::error('No current workspace.');
        }

        return Response::structured(['data' => Insights::for([$workspace->id], 90)['formula']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
