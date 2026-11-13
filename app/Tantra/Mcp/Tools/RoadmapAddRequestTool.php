<?php

declare(strict_types=1);

namespace App\Tantra\Mcp\Tools;

use App\Tantra\Roadmap\Roadmap;
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

#[Name('roadmap_add_request')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Ask for a feature on Tantra\'s public roadmap. It lands in Q: Requests at once, under your name (first name shown publicly; your email stays private). Plain text: title up to 120 characters, description up to 2000.')]
class RoadmapAddRequestTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:'.Roadmap::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Roadmap::DESCRIPTION_MAX],
            'product' => ['nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
        ]);

        $user = $request->user();
        $item = Roadmap::request($data + ['name' => $user?->name, 'email' => $user?->email]);

        return Response::structured(['item' => $item->publicArray(), 'url' => url('/roadmap').'#item-'.$item->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('What Tantra should do for you, in one line.'),
            'description' => $schema->string()->description('Why it matters and what you do today.'),
            'product' => $schema->string()->enum(array_keys(Roadmap::PRODUCTS))->description('Which product. Default content-engine.'),
        ];
    }
}
