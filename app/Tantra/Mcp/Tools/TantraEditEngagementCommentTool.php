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
#[Description('Edit or leave out one entry of a Tantra review item\'s team engagement thread, by its index from tantra-get-review-item-tool. Leaving out a comment also leaves out the replies under it. Only the review owner, and only before the post is published. No em or en dashes.')]
class TantraEditEngagementCommentTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'text' => ['nullable', 'string', 'max:1500'],
            'excluded' => ['nullable', 'boolean'],
        ]);
        $item = $this->itemOf($request);
        if ($item === null) {
            return Response::error('Review item not found.');
        }

        $excluded = array_key_exists('excluded', $validated) && $validated['excluded'] !== null ? (bool) $validated['excluded'] : null;
        if ($error = $this->attempt(fn () => ReviewActions::editThreadEntry($request->user(), $item, (int) $validated['index'], $validated['text'] ?? null, $excluded))) {
            return $error;
        }

        $entry = collect($item->refresh()->thread())->firstWhere('index', (int) $validated['index']);

        return Response::structured(['index' => (int) $validated['index'], 'text' => $entry['text'] ?? null, 'edited' => $entry['edited'] ?? false, 'excluded' => $entry['excluded'] ?? false]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_item_id' => $schema->string()->required()->description('UUID of the review item.'),
            'index' => $schema->integer()->required()->description('The entry\'s index in the thread.'),
            'text' => $schema->string()->description('The new text, plain, no em or en dashes.'),
            'excluded' => $schema->boolean()->description('true to leave the entry out, false to bring it back.'),
        ];
    }
}
