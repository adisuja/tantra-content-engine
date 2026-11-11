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
#[IsDestructive(true)]
#[IsOpenWorld(true)]
#[Description('Approve a Tantra review item. The post goes into its planned slot if that is free and ahead, otherwise the next free slot of its channel, exactly as approving on the review screen. Only the workspace\'s review owner can approve.')]
class TantraApproveReviewItemTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $item = $this->itemOf($request);
        if ($item === null) {
            return Response::error('Review item not found.');
        }

        if ($error = $this->attempt(fn () => ReviewActions::approve($request->user(), $item, $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null))) {
            return $error;
        }

        return Response::structured($this->summary($item->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_item_id' => $schema->string()->required()->description('UUID of the review item.'),
            'note' => $schema->string()->description('Optional note kept with the approval.'),
        ];
    }
}
