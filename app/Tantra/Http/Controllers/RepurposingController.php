<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Agent\Admin;
use App\Tantra\Repurpose\Cadence;
use App\Tantra\Repurpose\RepurposePreset;
use App\Tantra\Repurpose\RepurposeRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * tantra: the Repurposing tab (PRD 25.10): the cadence, each network's preset
 * and the Account's own rules. The Account owner and admins change the cadence
 * and rules; only admins change presets. The same actions answer the API.
 */
class RepurposingController extends Controller
{
    public function page(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('tantra/repurposing/Index', [
            ...$this->state($user),
            'canEdit' => $this->canEdit($user),
            'canEditPresets' => Admin::is($user),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($request->user())]);
    }

    public function cadence(Request $request): JsonResponse
    {
        abort_unless($this->canEdit($request->user()), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'sequence' => ['required', 'array', 'min:1', 'max:12'],
            'sequence.*.network' => ['required', 'distinct', Rule::in(Cadence::NETWORKS)],
            'sequence.*.week' => ['required', 'integer', 'min:0', 'max:52'],
            'sequence.*.enabled' => ['required', 'boolean'],
        ]);
        $cadence = Cadence::query()->firstOrNew(['account_id' => $request->user()->account_id, 'workspace_id' => null]);
        $cadence->forceFill([
            'enabled' => $data['enabled'],
            'sequence' => collect($data['sequence'])->map(fn (array $s): array => ['network' => $s['network'], 'week' => (int) $s['week'], 'enabled' => (bool) $s['enabled']])->sortBy('week')->values()->all(),
        ])->save();

        return response()->json(['message' => 'Cadence saved.', 'data' => $this->state($request->user())['cadence']]);
    }

    public function rules(Request $request, string $network): JsonResponse
    {
        abort_unless($this->canEdit($request->user()) && in_array($network, Cadence::NETWORKS, true), 403);
        $rules = trim((string) ($request->validate(['rules' => ['nullable', 'string', 'max:4000']])['rules'] ?? ''));
        if ($rules === '') {
            RepurposeRule::query()->where('account_id', $request->user()->account_id)->where('network', $network)->delete();
        } else {
            RepurposeRule::query()->updateOrCreate(
                ['account_id' => $request->user()->account_id, 'network' => $network],
                ['rules' => $rules, 'updated_by' => $request->user()->id],
            );
        }

        return response()->json(['message' => 'Saved.']);
    }

    /** Admins: upsert presets (the content repo pushes knowledge/content/repurpose/*.md through the API). */
    public function presets(Request $request): JsonResponse
    {
        abort_unless(Admin::is($request->user()) || $this->isOwner($request->user()), 403);
        $data = $request->validate([
            'presets' => ['required', 'array', 'max:30'],
            'presets.*.network' => ['required', Rule::in(Cadence::NETWORKS)],
            'presets.*.source' => ['nullable', 'string', 'max:32'],
            'presets.*.title' => ['required', 'string', 'max:160'],
            'presets.*.rules' => ['required', 'string', 'max:60000'],
        ]);
        foreach ($data['presets'] as $p) {
            $preset = RepurposePreset::query()->firstOrNew(['source' => $p['source'] ?? 'linkedin', 'network' => $p['network']]);
            $changed = $preset->rules !== $p['rules'];
            $preset->forceFill([
                'title' => $p['title'], 'rules' => $p['rules'], 'updated_by' => $request->user()->id,
                'version' => $preset->exists ? $preset->version + ($changed ? 1 : 0) : 1,
            ])->save();
        }

        return response()->json(['message' => count($data['presets']).' presets saved.']);
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        $cadence = Cadence::for($user->account_id);
        $custom = RepurposeRule::query()->where('account_id', $user->account_id)->pluck('rules', 'network');

        return [
            'cadence' => ['enabled' => (bool) $cadence->enabled, 'sequence' => $cadence->sequence],
            'presets' => RepurposePreset::query()->orderBy('network')->get()->map(fn (RepurposePreset $p): array => [
                'network' => $p->network, 'source' => $p->source, 'title' => $p->title, 'rules' => $p->rules, 'version' => $p->version,
                'custom' => $custom->get($p->network),
            ])->values(),
            'networks' => Cadence::NETWORKS,
            'custom' => $custom,
        ];
    }

    private function canEdit(User $user): bool
    {
        return $this->isOwner($user) || Admin::is($user);
    }

    private function isOwner(User $user): bool
    {
        return $user->account?->owner_id === $user->id;
    }
}
