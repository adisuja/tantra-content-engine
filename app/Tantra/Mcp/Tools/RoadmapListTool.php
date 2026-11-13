<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Roadmap\Column;
use App\Tantra\Roadmap\Roadmap;
use App\Tantra\Roadmap\RoadmapItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('roadmap_list')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the cards on Tantra\'s public product roadmap, column by column: q (Q: Requests), planned, in_progress, beta, shipped. Filter by product (content-engine, tantra-app, crm) or column. The instance owner also sees who asked for each request.')]
class RoadmapListTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $filters = $request->validate([
            'product' => ['nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'column' => ['nullable', 'string', Rule::in(Column::values())],
        ]);

        $payload = Roadmap::payload($filters['product'] ?? null, $filters['column'] ?? null);

        // The owner sees who asked; everyone else the public card.
        if (Roadmap::canSeeRequesters($request->user())) {
            $private = RoadmapItem::query()->whereIn('id', array_column($payload['items'], 'id'))->get()->keyBy('id');
            $payload['items'] = array_map(fn (array $item): array => $private->get($item['id'])?->privateArray() ?? $item, $payload['items']);
        }

        return Response::structured($payload);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => $schema->string()->enum(array_keys(Roadmap::PRODUCTS))->description('Only this product.'),
            'column' => $schema->string()->enum(Column::values())->description('Only this column.'),
        ];
    }
}
