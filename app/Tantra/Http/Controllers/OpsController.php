<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Agent\AgentRunner;
use App\Tantra\Agent\Appliers;
use App\Tantra\Agent\ClaudeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * tantra: Studio ops (PRD 25.4), for admins only (EnsureTantraAdmin): agent
 * jobs with their inputs and logs, Claude accounts, runners. The same actions
 * answer the ops API (owner token plus admin) and the ops screen.
 */
class OpsController extends Controller
{
    public function page(Request $request): Response
    {
        return Inertia::render('tantra/ops/Index', [
            'jobs' => $this->jobList($request),
            'accounts' => ClaudeAccount::query()->orderBy('created_at')->get()->map->toPublic()->values(),
            'runners' => AgentRunner::query()->with('claudeAccount')->orderBy('created_at')->get()->map->toPublic()->values(),
            'kinds' => Appliers::kinds(),
            'engineUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->jobList($request)]);
    }

    public function job(AgentJob $job): JsonResponse
    {
        return response()->json(['data' => $job->load(['workspace', 'requester', 'runner'])->toOps(withDetail: true)]);
    }

    public function enqueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Appliers::kinds())],
            'input' => ['nullable', 'array'],
            'workspace_id' => ['nullable', 'uuid'],
            'claude_account_id' => ['nullable', 'uuid'],
        ]);
        $job = AgentJobs::enqueue($data['kind'], $data['input'] ?? [], $request->user(), $data['workspace_id'] ?? null);
        if (filled($data['claude_account_id'] ?? null) && $job->status === AgentJob::QUEUED) {
            $job->forceFill(['claude_account_id' => $data['claude_account_id']])->save();
            AgentJobs::dispatch($job);
        }

        return response()->json(['data' => $job->refresh()->toOps()], 201);
    }

    public function run(Request $request, AgentJob $job): JsonResponse
    {
        AgentJobs::run($job, $request->user());

        return response()->json(['data' => $job->refresh()->toOps()]);
    }

    public function retry(AgentJob $job): JsonResponse
    {
        AgentJobs::retry($job);

        return response()->json(['data' => $job->refresh()->toOps()]);
    }

    public function cancel(AgentJob $job): JsonResponse
    {
        AgentJobs::cancel($job);

        return response()->json(['data' => $job->refresh()->toOps()]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $this->accountRules($request, creating: true);
        $account = ClaudeAccount::query()->create([...$data, 'owner_user_id' => $request->user()->id]);

        return response()->json(['data' => $account->toPublic()], 201);
    }

    public function updateAccount(Request $request, ClaudeAccount $account): JsonResponse
    {
        $data = array_filter($this->accountRules($request, creating: false), fn ($v) => $v !== null);
        $account->forceFill($data)->save();

        return response()->json(['data' => $account->toPublic()]);
    }

    public function destroyAccount(ClaudeAccount $account): JsonResponse
    {
        $account->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** Test an account with a ping job: a cloud task starts a run; a local account waits for its runner. */
    public function testAccount(Request $request, ClaudeAccount $account): JsonResponse
    {
        $job = AgentJob::query()->create([
            'kind' => 'ping', 'status' => AgentJob::QUEUED, 'account_id' => $request->user()->account_id,
            'requested_by' => $request->user()->id, 'claude_account_id' => $account->id, 'input' => ['say' => 'pong'],
        ]);
        $fired = $account->kind === 'cloud_task' && AgentJobs::dispatch($job);
        $account->forceFill([
            'last_test_at' => now(),
            'last_test_result' => $account->kind === 'cloud_task'
                ? ($fired ? 'Run started; the ping job finishes when it answers.' : 'Could not start a run: '.(string) data_get(collect($job->refresh()->log ?? [])->last(), 'line', 'no answer'))
                : 'Ping job queued; it finishes when the local runner checks in.',
        ])->save();

        return response()->json(['data' => ['account' => $account->toPublic(), 'job' => $job->refresh()->toOps()]]);
    }

    public function storeRunner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['cloud_task', 'local'])],
            'claude_account_id' => ['nullable', 'uuid', Rule::exists('tantra_claude_accounts', 'id')],
            'kinds' => ['nullable', 'array'], 'kinds.*' => ['string', 'max:32'],
        ]);
        [$runner, $token] = AgentRunner::issue($data['name'], $data['kind'], $data['claude_account_id'] ?? null, $data['kinds'] ?? null);

        return response()->json(['data' => $runner->toPublic(), 'token' => $token, 'message' => 'Copy the token now; it is not shown again.'], 201);
    }

    public function revokeRunner(AgentRunner $runner): JsonResponse
    {
        $runner->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'Revoked.']);
    }

    /** @return list<array<string, mixed>> */
    private function jobList(Request $request): array
    {
        return AgentJob::query()->with(['workspace', 'requester', 'runner'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->latest()->limit(100)->get()->map->toOps()->values()->all();
    }

    /** @return array<string, mixed> */
    private function accountRules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'kind' => [$req, Rule::in(ClaudeAccount::KINDS)],
            'fire_url' => ['nullable', 'url:https', 'max:500', 'starts_with:https://api.anthropic.com/'],
            'secret' => ['nullable', 'string', 'max:500'],
            'config_dir' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._~\/-]+$/'],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }
}
