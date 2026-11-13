<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Roadmap\Column;
use App\Tantra\Roadmap\Roadmap;
use App\Tantra\Roadmap\RoadmapItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the roadmap's JSON API. Reading and asking are public; changing a
 * card needs the instance owner's API key or a key with roadmap:write.
 */
class RoadmapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product' => ['nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'column' => ['nullable', 'string', Rule::in(Column::values())],
        ]);

        return response()
            ->json(Roadmap::payload($filters['product'] ?? null, $filters['column'] ?? null))
            ->header('Cache-Control', 'public, max-age=60');
    }

    /** The request form: lands in Q at once. Honeypot, 5 an hour per IP, plain text. */
    public function request(Request $request): JsonResponse
    {
        $key = 'tantra-roadmap-request:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, Roadmap::REQUESTS_PER_HOUR)) {
            return response()->json([
                'message' => 'Thanks, that is plenty for now. Try again in an hour.',
            ], Response::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        // A bot fills every field it sees. Answer as if it worked; keep nothing.
        if (filled($request->input('website'))) {
            RateLimiter::hit($key, 3600);

            return response()->json(['message' => 'Thanks. It is in Q: Requests.', 'item' => null], Response::HTTP_CREATED);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:'.Roadmap::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Roadmap::DESCRIPTION_MAX],
            'product' => ['nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
        ]);

        if (mb_strlen(Roadmap::line($data['title'], Roadmap::TITLE_MAX)) < 3) {
            return response()->json(['message' => 'Tell us a little more.', 'errors' => ['title' => ['Tell us a little more.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        RateLimiter::hit($key, 3600);
        $item = Roadmap::request($data, $request->ip());

        return response()->json([
            'message' => 'Thanks. It is in Q: Requests.',
            'item' => $item->publicArray(),
        ], Response::HTTP_CREATED);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:'.Roadmap::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Roadmap::DESCRIPTION_MAX],
            'column' => ['nullable', 'string', Rule::in(Column::values())],
            'product' => ['nullable', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'category' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(['item' => Roadmap::present(Roadmap::create($data), $request->user())], Response::HTTP_CREATED);
    }

    public function update(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->authorizeWrite($request);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:'.Roadmap::TITLE_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.Roadmap::DESCRIPTION_MAX],
            'column' => ['sometimes', 'string', Rule::in(Column::values())],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'product' => ['sometimes', 'string', Rule::in(array_keys(Roadmap::PRODUCTS))],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        return response()->json(['item' => Roadmap::present(Roadmap::update($item, $data), $request->user())]);
    }

    public function move(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->authorizeWrite($request);
        $data = $request->validate([
            'column' => ['required', 'string', Rule::in(Column::values())],
            'position' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $item = Roadmap::move($item, Column::from($data['column']), isset($data['position']) ? (int) $data['position'] : null);

        return response()->json(['item' => Roadmap::present($item->refresh(), $request->user())]);
    }

    public function destroy(Request $request, RoadmapItem $item): JsonResponse
    {
        $this->authorizeWrite($request);
        Roadmap::delete($item);

        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless(Roadmap::canWrite($request->user()), Response::HTTP_FORBIDDEN, 'Only the instance owner or a key with the roadmap:write scope can change the roadmap.');
    }
}
