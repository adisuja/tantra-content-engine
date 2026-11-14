<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Roadmap\Column;
use App\Tantra\Roadmap\Roadmap;
use Inertia\Inertia;
use Inertia\Response;

/**
 * tantra: the public roadmap page. Read only, no login. Its own root view
 * carries the title, description and a plain HTML copy of the board for
 * crawlers, since the Engine has no server side rendering.
 */
class RoadmapPageController extends Controller
{
    public const TITLE = 'Tantra product roadmap: what we are building next';

    public const DESCRIPTION = 'See what Tantra is building for the Content Engine: new channels, publishing and analytics. Planned, in progress, beta and shipped. Ask for a feature in a minute.';

    public function __invoke(): Response
    {
        $roadmap = Roadmap::payload();

        return Inertia::render('tantra/roadmap/Public', [
            'tantraTitle' => self::TITLE,
            'roadmap' => $roadmap,
            'endpoints' => [
                'json' => url('/api/roadmap'),
                'requests' => url('/api/roadmap/requests'),
                'feed' => url('/roadmap/feed'),
                'llms' => url('/roadmap/llms.txt'),
            ],
        ])->rootView('tantra::roadmap')->withViewData([
            'seo' => [
                'title' => self::TITLE,
                'description' => self::DESCRIPTION,
                'url' => url('/roadmap'),
                'image' => url('/tantra/mark-ink.png'),
            ],
            'columns' => collect(Column::cases())->map(fn (Column $column): array => [
                'label' => $column->label(),
                'items' => collect($roadmap['items'])->where('column', $column->value)->values()->all(),
            ])->all(),
        ]);
    }
}
