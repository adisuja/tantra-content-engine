<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tantra\Templates\ContentTemplate;
use App\Tantra\Templates\TemplateIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * tantra: content templates (PRD 25.9): the gallery under Create, "Add from a
 * post", and the API that seeds the samples and lets the content repo pull new
 * ones. Seeding is the Account owner's (EnsureOwnerToken); reading and adding
 * from a post is anyone's in the Account.
 */
class TemplatesController extends Controller
{
    public function page(Request $request): Response
    {
        $templates = ContentTemplate::visibleTo($request->user()->account_id)->where('status', '!=', 'archived')
            ->orderByRaw("case when origin = 'intake' then 0 else 1 end")->orderByDesc('updated_at')->orderBy('key')->get();

        return Inertia::render('tantra/templates/Index', [
            'templates' => $templates->map(fn (ContentTemplate $t): array => [...$t->toCard(), 'state' => TemplateIntake::state($t)])->values(),
            'platforms' => $templates->countBy('platform'),
            'formats' => $templates->pluck('format')->filter()->unique()->sort()->values(),
        ]);
    }

    public function show(Request $request, ContentTemplate $template): JsonResponse
    {
        abort_unless($template->account_id === null || $template->account_id === $request->user()->account_id, 404);

        return response()->json(['data' => [...$template->toDetail(), 'state' => TemplateIntake::state($template)]]);
    }

    public function intake(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url:https', 'max:500'], 'note' => ['nullable', 'string', 'max:500']]);
        $template = TemplateIntake::start($request->user(), $data['url'], $data['note'] ?? null);
        $state = TemplateIntake::state($template);

        return response()->json([
            'message' => match ($state) {
                'waiting' => 'Added. The team builds it next; it shows here as soon as it is ready.',
                'live' => 'This post is already a template.',
                default => 'Building your template. It shows here as soon as it is ready.',
            },
            'data' => [...$template->toCard(), 'state' => $state],
        ], 201);
    }

    /** API: every template the token's Account can see, newest first; `since` and `origin` for the content repo's pull. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', Rule::in(ContentTemplate::PLATFORMS)], 'format' => ['nullable', 'string', 'max:80'],
            'origin' => ['nullable', Rule::in(['seed', 'intake'])], 'since' => ['nullable', 'date'], 'full' => ['nullable', 'boolean'],
        ]);
        $rows = ContentTemplate::visibleTo($request->user()->account_id)
            ->when($data['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($data['format'] ?? null, fn ($q, $v) => $q->where('format', $v))
            ->when($data['origin'] ?? null, fn ($q, $v) => $q->where('origin', $v))
            ->when($data['since'] ?? null, fn ($q, $v) => $q->where('updated_at', '>', $v))
            ->orderByDesc('updated_at')->limit(1000)->get();
        $full = (bool) ($data['full'] ?? false);

        return response()->json(['data' => $rows->map(fn (ContentTemplate $t): array => $full ? [...$t->toDetail(), 'sample_md' => $t->sample_md] : $t->toCard())->values()]);
    }

    /** API, Account owner: upsert seeded templates by key (the samples from tantra-content). */
    public function seed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'templates' => ['required', 'array', 'max:300'],
            'templates.*.key' => ['required', 'string', 'max:32', 'regex:/^[A-Z]{1,4}\d{1,3}$/'],
            'templates.*.title' => ['required', 'string', 'max:200'],
            'templates.*.platform' => ['nullable', Rule::in(ContentTemplate::PLATFORMS)],
            'templates.*.format' => ['nullable', 'string', 'max:80'],
            'templates.*.family' => ['nullable', 'string', 'max:40'],
            'templates.*.archetype' => ['nullable', 'string', 'max:300'],
            'templates.*.creator' => ['nullable', 'string', 'max:120'],
            'templates.*.source_url' => ['nullable', 'url', 'max:500'],
            'templates.*.description' => ['nullable', 'string', 'max:3000'],
            'templates.*.spec' => ['nullable', 'string', 'max:60000'],
            'templates.*.copy_template' => ['nullable', 'string', 'max:8000'],
            'templates.*.palette' => ['nullable', 'array', 'max:12'],
            'templates.*.sample_md' => ['nullable', 'string', 'max:120000'],
        ]);
        $created = $updated = 0;
        foreach ($data['templates'] as $row) {
            $template = ContentTemplate::query()->firstOrNew(['key' => $row['key']]);
            if ($template->exists && $template->origin !== 'seed') {
                continue; // never overwrite a template someone built from a post
            }
            $template->exists ? $updated++ : $created++;
            $template->forceFill([...$row, 'platform' => $row['platform'] ?? 'linkedin', 'origin' => 'seed', 'status' => 'live', 'account_id' => null])->save();
        }

        return response()->json(['message' => "{$created} added, {$updated} updated.", 'created' => $created, 'updated' => $updated]);
    }

    /** API, Account owner: the preview image of a seeded template (a sample's contact sheet or first frame). */
    public function preview(Request $request, string $key): JsonResponse
    {
        $template = ContentTemplate::query()->where('key', $key)->firstOrFail();
        $file = $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192']])['file'];
        $path = $file->storePubliclyAs("tantra/templates/{$template->key}", 'preview.'.$file->extension());
        $template->forceFill(['preview_path' => $path])->save();

        return response()->json(['message' => 'Saved.', 'preview' => Storage::url($path)]);
    }
}
