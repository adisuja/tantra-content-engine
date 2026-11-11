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
#[Description('Add a review comment to a Tantra review item. Start it with @Engine to send it to the next batch run. This is a note for the team, never a LinkedIn comment.')]
class TantraCommentReviewItemTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $item = $this->itemOf($request);
        if ($item === null) {
            return Response::error('Review item not found.');
        }

        if ($error = $this->attempt(fn () => ReviewActions::comment($request->user(), $item, $validated['body']))) {
            return $error;
        }

        return Response::structured(['saved' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_item_id' => $schema->string()->required()->description('UUID of the review item.'),
            'body' => $schema->string()->required()->description('The comment.'),
        ];
    }
}
