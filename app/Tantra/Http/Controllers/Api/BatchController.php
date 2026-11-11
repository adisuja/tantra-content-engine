<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'engine_batch_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['nullable', 'string', 'max:255'],
            'dates_label' => ['nullable', 'string', 'max:255'],
            'source_commit' => ['nullable', 'string', 'max:64'],
            'force_reopen' => ['sometimes', 'array'],
            'force_reopen.*' => ['string', 'max:32'],
            'posts' => ['required', 'array', 'min:1', 'max:100'],
            'posts.*.engine_post_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'posts.*.parent_engine_post_id' => ['nullable', 'string', 'max:32'],
            'posts.*.profile' => ['required', 'string', 'max:64'],
            'posts.*.platform' => ['required', 'string', Rule::in(['linkedin', 'x'])],
            'posts.*.date' => ['nullable', 'date_format:Y-m-d'],
            'posts.*.time' => ['nullable', 'date_format:H:i'],
            'posts.*.tz' => ['nullable', 'timezone:all'],
            'posts.*.text' => ['nullable', 'string', 'max:10000'],
            'posts.*.first_comment' => ['nullable', 'string', 'max:3000'],
            'posts.*.thread' => ['nullable', 'array'],
            'posts.*.format' => ['nullable', 'string', 'max:255'],
            'posts.*.pillar' => ['nullable', 'string', 'max:255'],
            'posts.*.short' => ['nullable', 'string', 'max:255'],
            'posts.*.idea' => ['nullable', 'string'],
            'posts.*.sample' => ['nullable', 'array'],
            'posts.*.copy_basis' => ['nullable', 'string'],
            'posts.*.design_system' => ['nullable', 'string', 'max:255'],
            'posts.*.media' => ['nullable', 'array'],
            'posts.*.upload_tokens' => ['nullable', 'array', 'max:20'],
            'posts.*.upload_tokens.*' => ['uuid'],
            'posts.*.preview_paths' => ['nullable', 'array', 'max:20'],
            'posts.*.preview_paths.*' => ['string', 'max:512', 'starts_with:tantra/previews/'],
            'posts.*.sources' => ['nullable', 'array'],
            'posts.*.numbers_for_signoff' => ['nullable', 'array'],
            'posts.*.engagement_comments' => ['nullable', 'array', 'max:20'],
            'posts.*.flags' => ['nullable', 'array'],
            'posts.*.cta' => ['nullable', 'string', 'max:64'],
            'posts.*.cta_date' => ['nullable', 'date_format:Y-m-d'],
            'posts.*.visual_copy' => ['nullable', 'array'],
            'posts.*.resource' => ['nullable', 'array'],
            'posts.*.mentions' => ['nullable', 'array', 'max:20'],
            'posts.*.mentions.*' => ['string', 'max:120'],
            'posts.*.profile_card' => ['nullable', 'array'],
            'posts.*.profile_card.name' => ['nullable', 'string', 'max:120'],
            'posts.*.profile_card.headline' => ['nullable', 'string', 'max:255'],
            'posts.*.profile_card.avatar' => ['nullable', 'url:https', 'max:1024'],
            'posts.*.profile_card.kind' => ['nullable', Rule::in(['person', 'company'])],
            'posts.*.profile_card.followers_line' => ['nullable', 'string', 'max:120'],
            'posts.*.profile_card.x_handle' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => ImportBatch::execute($request->user(), $data)]);
    }

    /** Feedback addressed to the engine (@Engine) for the next batch session. */
    public function feedback(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($batch->workspace?->account_id === $request->user()->account_id, 404);

        $events = ReviewEvent::query()
            ->whereIn('review_item_id', $batch->items()->select('id'))
            ->whereIn('kind', [EventKind::Comment->value, EventKind::RequestChanges->value])
            ->with(['item:id,engine_post_id', 'user:id,name'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (ReviewEvent $e): bool => $e->kind === EventKind::RequestChanges || str_contains((string) $e->body, '@Engine'))
            ->map(fn (ReviewEvent $e): array => [
                'engine_post_id' => $e->item->engine_post_id,
                'kind' => $e->kind->value,
                'body' => $e->body,
                'by' => $e->user?->name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $events]);
    }
}
