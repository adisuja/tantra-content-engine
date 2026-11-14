<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Mcp\TantraMcp;
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

#[Name('roadmap_update')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Edit a roadmap card: title, description (the first paragraph is the card line, the rest opens on expand), category, product, and optionally its column and position. Plain text, no en or em dashes. Only the instance owner or a key with the roadmap:write scope.')]
class RoadmapUpdateTool extends Tool
{
    use TantraMcp;

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! Roadmap::canWrite($request->user())) {
            return Response::error('Only the instance owner or a key with the roadmap:write scope can change the roadmap.');
        }

        $data = $request->validate([
            'id' => ['required', 'string', 'ulid'],
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:'.Roadmap::TITLE_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.Roadmap::DESCRIPTION_MAX],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'product' => ['sometimes', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'column' => ['sometimes', 'string', Rule::in(Column::values())],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $item = RoadmapItem::query()->find($data['id']);
        if ($item === null) {
            return Response::error('Roadmap card not found.');
        }

        unset($data['id']);
        if ($error = $this->attempt(fn () => Roadmap::update($item, $data))) {
            return $error;
        }

        return Response::structured(['item' => Roadmap::present($item->refresh(), $request->user())]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required()->description('The card id (a ULID from roadmap_list).'),
            'title' => $schema->string()->description('One line, up to 120 characters.'),
            'description' => $schema->string()->description('Up to 2000 characters. A blank line splits the card line from the detail.'),
            'category' => $schema->string()->description('For example Channels, Publishing, Engagement, Analytics, AI, Platform.'),
            'product' => $schema->string()->enum(array_keys(Roadmap::PRODUCTS)),
            'column' => $schema->string()->enum(Column::values()),
            'position' => $schema->integer()->description('Place in the column, 1 is the top.'),
        ];
    }
}
