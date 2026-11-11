<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Mcp\TantraMcp;
use App\Tantra\Review\Models\ReviewEvent;
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
#[Description('Get one Tantra review item: its copy, first comment, the team engagement thread as it will be posted (with each entry\'s index, author profile, edits and removals), and its review history.')]
class TantraGetReviewItemTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $item = $this->itemOf($request);
        if ($item === null) {
            return Response::error('Review item not found.');
        }

        return Response::structured([
            ...$this->summary($item),
            'text' => data_get($item->payload, 'text'),
            'first_comment' => data_get($item->payload, 'first_comment'),
            'thread' => array_map(fn (array $e): array => [
                'index' => $e['index'], 'by' => $e['by'] ?? null, 'reply_to' => $e['reply_to'] ?? null,
                'text' => $e['text'], 'edited' => $e['edited'], 'excluded' => $e['excluded'],
            ], $item->thread()),
            'history' => $item->events()->with('user:id,name')->orderBy('created_at')->get()
                ->map(fn (ReviewEvent $e): array => ['kind' => $e->kind->value, 'by' => $e->user?->name, 'body' => $e->body, 'at' => $e->created_at?->toIso8601ZuluString()])
                ->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['review_item_id' => $schema->string()->required()->description('UUID of the review item.')];
    }
}
