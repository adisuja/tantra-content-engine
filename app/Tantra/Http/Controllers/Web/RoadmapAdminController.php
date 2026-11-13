<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Channels\ChannelApps;
use App\Tantra\Roadmap\Column;
use App\Tantra\Roadmap\Roadmap;
use App\Tantra\Roadmap\RoadmapItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * tantra: Settings > Roadmap. The public page is read only; the instance
 * owner moves, edits and removes cards here, and sees who asked.
 */
class RoadmapAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeOwner($request);

        return Inertia::render('tantra/roadmap/Manage', [
            'items' => RoadmapItem::query()->orderBy('position')->orderByDesc('created_at')->get()
                ->map(fn (RoadmapItem $item): array => $item->privateArray())->values()->all(),
            'columns' => collect(Column::cases())->map(fn (Column $c): array => ['key' => $c->value, 'label' => $c->label()])->all(),
            'products' => collect(Roadmap::PRODUCTS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'categories' => Roadmap::CATEGORIES,
            'publicUrl' => url('/roadmap'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        $item = Roadmap::create($request->validate($this->rules(required: true)));

        return response()->json(['message' => 'Added.', 'item' => $item->privateArray()], HttpResponse::HTTP_CREATED);
    }

    public function update(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->authorizeOwner($request);
        $item = Roadmap::update($item, $request->validate($this->rules(required: false)));

        return response()->json(['message' => 'Saved.', 'item' => $item->privateArray()]);
    }

    public function move(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            'column' => ['required', 'string', Rule::in(Column::values())],
            'position' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $item = Roadmap::move($item, Column::from($data['column']), isset($data['position']) ? (int) $data['position'] : null);

        return response()->json(['message' => 'Moved to '.$item->column->label().'.', 'item' => $item->privateArray()]);
    }

    public function destroy(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->authorizeOwner($request);
        Roadmap::delete($item);

        return response()->json(['message' => 'Deleted.']);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'title' => [...$sometimes, 'required', 'string', 'min:3', 'max:'.Roadmap::TITLE_MAX],
            'description' => [...$sometimes, 'nullable', 'string', 'max:'.Roadmap::DESCRIPTION_MAX],
            'column' => [...$sometimes, 'nullable', 'string', Rule::in(Column::values())],
            'product' => [...$sometimes, 'nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'category' => [...$sometimes, 'nullable', 'string', 'max:40'],
        ];
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless(ChannelApps::canManage($request->user()), HttpResponse::HTTP_FORBIDDEN, 'Only the Account owner can manage the roadmap.');
    }
}
