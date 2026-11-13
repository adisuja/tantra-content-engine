<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tantra\Roadmap\Column;
use App\Tantra\Roadmap\Roadmap;
use App\Tantra\Roadmap\RoadmapItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** tantra: the roadmap as files: JSON, an Atom feed and llms.txt for agents. */
class RoadmapFeedController extends Controller
{
    public function json(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product' => ['nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'column' => ['nullable', 'string', Rule::in(Column::values())],
        ]);

        return response()
            ->json(Roadmap::payload($filters['product'] ?? null, $filters['column'] ?? null))
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=60');
    }

    /** New cards and shipped ones, newest first. */
    public function atom(): Response
    {
        $items = RoadmapItem::query()
            ->orderByRaw('coalesce(shipped_at, created_at) desc')
            ->limit(50)
            ->get();

        $updated = $items->map(fn (RoadmapItem $item) => $item->shipped_at ?? $item->created_at)->filter()->max() ?? now();

        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n".view('tantra::roadmap-feed', [
            'items' => $items,
            'updated' => $updated,
            'self' => url('/roadmap/feed'),
            'page' => url('/roadmap'),
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function llms(): Response
    {
        return response(view('tantra::roadmap-llms', [
            'base' => rtrim(url('/'), '/'),
            'columns' => Column::cases(),
            'products' => Roadmap::PRODUCTS,
        ])->render(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
