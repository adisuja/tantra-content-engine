<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Mcp\TantraMcp;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
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
#[Description('List the Tantra review items (posts sent from the content engine for review) in the current workspace. Filter by engine batch number (batch) and status: in_review, changes_requested, approved, scheduled, published, rejected. Superseded versions are left out.')]
class TantraListReviewItemsTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'batch' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (ReviewStatus $s): string => $s->value, ReviewStatus::cases()))],
        ]);
        $workspace = $this->workspaceOf($request);
        if ($workspace === null) {
            return Response::error('No current workspace.');
        }

        $items = ReviewItem::query()->where('workspace_id', $workspace->id)
            ->where('review_status', '!=', ReviewStatus::Superseded->value)
            ->when($validated['batch'] ?? null, fn ($q, $b) => $q->where('engine_batch_id', $b))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('review_status', $s))
            ->orderBy('planned_date')->orderBy('engine_post_id')->limit(200)->get();

        return Response::structured(['data' => $items->map(fn (ReviewItem $i): array => $this->summary($i))->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'batch' => $schema->string()->description('Engine batch number, for example "4".'),
            'status' => $schema->string()->description('One review status: in_review, changes_requested, approved, scheduled, published or rejected.'),
        ];
    }
}
