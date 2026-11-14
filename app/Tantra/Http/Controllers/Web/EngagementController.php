<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Engagement\CrmEngagement;
use App\Tantra\Engagement\CrmRefused;
use App\Tantra\Engagement\CrmUnreachable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * tantra: /tantra/engagement, the Engagement tab (PRD Engagement, phase 4). Same layout as the CRM's Engagement
 * screen: Posts, People, Pipeline and Sequences. Every read and edit goes through here to the CRM (App\Tantra\
 * Engagement\CrmEngagement), so the CRM key stays on the server. Approve and start is always a person's explicit
 * action: it needs an owner-level user and confirm=true, and nothing here calls it on its own.
 */
class EngagementController extends Controller
{
    public const TABS = ['posts', 'people', 'pipeline', 'sequences'];

    public function __construct(private readonly CrmEngagement $crm) {}

    public function page(Request $request, string $tab = 'posts'): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('tantra/engagement/Index', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'posts',
            'crm' => [...CrmEngagement::connection($user), 'url' => 'https://crm.usetantra.com/engagement'],
            'canManage' => CrmEngagement::canManage($user),
            'source' => $request->query('source'),
            'kind' => $request->query('kind'),
        ]);
    }

    // ------------------------------------------------------------------ posts
    public function sources(Request $request): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', 'engagement/sources'));
    }

    public function source(Request $request, string $source): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', "engagement/sources/{$source}"));
    }

    public function enable(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'backfill' => ['nullable', Rule::in(['requesters', 'all', 'none'])],
        ]);

        return $this->relay($request, fn () => $this->crm->call('POST', "engagement/sources/{$source}/enable", [], array_filter($data, fn ($v) => $v !== null)), manage: true);
    }

    public function poll(Request $request, string $source): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('POST', "engagement/sources/{$source}/poll"), manage: true);
    }

    public function backlog(Request $request, string $source): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', "engagement/sources/{$source}/backlog"));
    }

    public function preview(Request $request, string $source): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', "engagement/sources/{$source}/preview"));
    }

    /** Approve and start: the post's sequences leave test mode. Only on a person's explicit, confirmed click. */
    public function approve(Request $request, string $source): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->relay($request, fn () => $this->crm->call('POST', "engagement/sources/{$source}/approve"), manage: true);
    }

    public function quotas(Request $request): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', 'engagement/quotas'));
    }

    // ------------------------------------------------------------------ people
    public function people(Request $request): JsonResponse
    {
        $q = $request->validate([
            'group' => ['nullable', Rule::in(['all', 'requested', 'commenters', 'likers', 'sharers', 'team'])],
            'source_id' => ['nullable', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->relay($request, fn () => $this->crm->call('GET', 'engagement/people', ['group' => $q['group'] ?? 'requested', ...Arr::except($q, 'group')]));
    }

    public function timeline(Request $request): JsonResponse
    {
        $q = $request->validate([
            'deal_id' => ['nullable', 'string', 'max:64'],
            'person' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->relay($request, fn () => $this->crm->call('GET', 'engagement/people/timeline', $q));
    }

    /** Mark as team, or not: team people are shown as results and never messaged. */
    public function team(Request $request): JsonResponse
    {
        $data = $request->validate([
            'deal_id' => ['nullable', 'string', 'max:64', 'required_without:person'],
            'person' => ['nullable', 'string', 'max:300'],
            'team' => ['required', 'boolean'],
        ]);

        return $this->relay($request, fn () => $this->crm->call('POST', 'engagement/people/team', [], array_filter($data, fn ($v) => $v !== null)), manage: true);
    }

    // ------------------------------------------------------------------ pipeline
    public function pipeline(Request $request): JsonResponse
    {
        $q = $request->validate([
            'source_id' => ['nullable', 'string', 'max:64'],
            'sender_id' => ['nullable', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->relay($request, fn () => $this->crm->call('GET', 'engagement/pipeline', $q));
    }

    /** Move a person to another stage. Only the stage changes; that stage's sequences start in the CRM. */
    public function move(Request $request, string $deal): JsonResponse
    {
        $data = $request->validate(['stage_id' => ['required', 'string', 'max:64']]);

        return $this->relay($request, fn () => $this->crm->call('PATCH', "deals/{$deal}", [], ['stage_id' => $data['stage_id']]), manage: true);
    }

    // ------------------------------------------------------------------ sequences
    public function sequenceBoard(Request $request): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', 'engagement/sequence-board'));
    }

    public function createSequence(Request $request): JsonResponse
    {
        $data = $request->validate(['stage_id' => ['required', 'string', 'max:64']]);

        return $this->relay($request, fn () => $this->crm->call('POST', 'engagement/sequences', [], $data), manage: true);
    }

    public function sequence(Request $request, string $sequence): JsonResponse
    {
        return $this->relay($request, fn () => $this->crm->call('GET', "automation/sequences/{$sequence}"));
    }

    /**
     * Edit a sequence in place: name, on or off, and its steps. Its settings (test mode among them) are never sent
     * from here, so going live stays with Approve and start.
     */
    public function updateSequence(Request $request, string $sequence): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused'])],
            'steps' => ['sometimes', 'array', 'max:20'],
            'steps.*' => ['array'],
            'steps.*.channel' => ['required', 'string', 'max:20'],
            'steps.*.action' => ['required', 'string', 'max:40'],
            'steps.*.body' => ['nullable', 'string', 'max:20000'],
            'steps.*.subject' => ['nullable', 'string', 'max:300'],
            'steps.*.wait' => ['nullable', 'array'],
            'steps.*.wait.amount' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'steps.*.wait.unit' => ['nullable', Rule::in(['minutes', 'hours', 'days', 'weeks'])],
        ]);
        // Each step goes back whole (the CRM rebuilds a step from its fields), so keep what the form does not edit.
        if (array_key_exists('steps', $data)) {
            $data['steps'] = array_values((array) $request->input('steps'));
        }

        return $this->relay($request, fn () => $this->crm->call('PATCH', "automation/sequences/{$sequence}", [], $data), manage: true);
    }

    // ------------------------------------------------------------------ the relay
    /** @param Closure(): array<string, mixed> $call */
    private function relay(Request $request, Closure $call, bool $manage = false): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $connection = CrmEngagement::connection($user);
        if (! $connection['connected']) {
            return response()->json(['message' => __('tantra.engagement.not_connected.'.$connection['reason']), 'code' => 'crm_not_connected', 'reason' => $connection['reason']], 409);
        }
        if ($manage && ! CrmEngagement::canManage($user)) {
            return response()->json(['message' => __('tantra.engagement.manage_only'), 'code' => 'forbidden'], 403);
        }

        try {
            return response()->json($call());
        } catch (CrmUnreachable $e) {
            report($e);

            return response()->json(['message' => __('tantra.engagement.unreachable'), 'code' => 'crm_unreachable'], 503);
        } catch (CrmRefused $e) {
            if ($e->status === 401) {
                return response()->json(['message' => __('tantra.engagement.not_connected.key_refused'), 'code' => 'crm_not_connected', 'reason' => 'key_refused'], 409);
            }
            // A missing CRM route or object reads as a 404 here; the CRM's own words otherwise.
            $status = in_array($e->status, [400, 403, 404, 409, 422, 429], true) ? $e->status : 400;

            return response()->json(['message' => $e->getMessage() !== '' ? $e->getMessage() : __('tantra.engagement.refused'), 'code' => $e->crmCode ?? 'crm_refused'], $status);
        }
    }
}
