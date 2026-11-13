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

#[Name('roadmap_move')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Move a roadmap card to another column (q, planned, in_progress, beta, shipped), at the end or at a position (1 is the top). Moving to shipped stamps the ship date. Only the instance owner or a key with the roadmap:write scope.')]
class RoadmapMoveTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        if (! Roadmap::canWrite($request->user())) {
            return Response::error('Only the instance owner or a key with the roadmap:write scope can change the roadmap.');
        }

        $data = $request->validate([
            'id' => ['required', 'string', 'ulid'],
            'column' => ['required', 'string', Rule::in(Column::values())],
            'position' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $item = RoadmapItem::query()->find($data['id']);
        if ($item === null) {
            return Response::error('Roadmap card not found.');
        }

        $item = Roadmap::move($item, Column::from($data['column']), isset($data['position']) ? (int) $data['position'] : null);

        return Response::structured(['item' => Roadmap::present($item->refresh(), $request->user())]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required()->description('The card id (a ULID from roadmap_list).'),
            'column' => $schema->string()->enum(Column::values())->required()->description('The column to move it to.'),
            'position' => $schema->integer()->description('Place in the column, 1 is the top. Default: the end.'),
        ];
    }
}
