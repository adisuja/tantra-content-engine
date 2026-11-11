<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Mcp\TantraMcp;
use App\Tantra\Review\Actions\ReviewActions;
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
#[Description('Send a Tantra review item back to the content engine with what to change. Only the workspace\'s review owner can do this. The note goes to the next batch run.')]
class TantraRequestChangesTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $item = $this->itemOf($request);
        if ($item === null) {
            return Response::error('Review item not found.');
        }

        if ($error = $this->attempt(fn () => ReviewActions::requestChanges($request->user(), $item, $validated['note']))) {
            return $error;
        }

        return Response::structured($this->summary($item->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_item_id' => $schema->string()->required()->description('UUID of the review item.'),
            'note' => $schema->string()->required()->description('What to change.'),
        ];
    }
}
