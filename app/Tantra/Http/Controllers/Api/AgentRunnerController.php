<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Agent\AgentRunner;
use App\Tantra\Agent\InvalidResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * tantra: the runner side of agent jobs (PRD 25.5): claim, read, log, finish.
 * A runner sees only jobs it claimed, or the one job it is asked to claim.
 */
class AgentRunnerController extends Controller
{
    public function claim(Request $request): JsonResponse|Response
    {
        $data = $request->validate(['kinds' => ['nullable', 'array'], 'kinds.*' => ['string', 'max:32'], 'job_id' => ['nullable', 'uuid']]);
        $job = AgentJobs::claim($this->runner($request), $data['kinds'] ?? null, $data['job_id'] ?? null);

        if ($job === null) {
            // Asked for one job that is no longer free: another run has it, so this one stops.
            return filled($data['job_id'] ?? null)
                ? response()->json(['message' => 'This job is not waiting any more.'], 409)
                : response()->noContent();
        }

        return response()->json(['data' => [...$this->payload($job), 'claim_token' => $job->claim_token]]);
    }

    public function show(Request $request, AgentJob $job): JsonResponse
    {
        $this->own($request, $job);

        return response()->json(['data' => $this->payload($job)]);
    }

    public function log(Request $request, AgentJob $job): JsonResponse
    {
        $this->own($request, $job);
        $data = $request->validate(['line' => ['required', 'string', 'max:2000'], 'session_url' => ['nullable', 'url:https', 'max:500']]);
        AgentJobs::log($job, $data['line'], $data['session_url'] ?? null);

        return response()->json(['message' => 'Logged.']);
    }

    public function complete(Request $request, AgentJob $job): JsonResponse
    {
        $this->own($request, $job);
        $result = (array) $request->validate(['result' => ['required', 'array']])['result'];
        try {
            $summary = AgentJobs::complete($job, $result);
        } catch (InvalidResult $e) {
            AgentJobs::log($job, 'Result not applied: '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Applied.', 'summary' => $summary]);
    }

    public function fail(Request $request, AgentJob $job): JsonResponse
    {
        $this->own($request, $job);
        AgentJobs::fail($job, (string) $request->validate(['error' => ['required', 'string', 'max:4000']])['error']);

        return response()->json(['message' => 'Marked failed.']);
    }

    private function runner(Request $request): AgentRunner
    {
        return $request->attributes->get('tantra_runner');
    }

    /** Only the run holding the current claim may read, log or finish a job (review B3). */
    private function own(Request $request, AgentJob $job): void
    {
        $token = (string) $request->header('X-Claim-Token', '');
        abort_unless($job->runner_id === $this->runner($request)->id, 404);
        abort_unless($token !== '' && is_string($job->claim_token) && hash_equals($job->claim_token, $token), 409, 'This run no longer holds the job.');
    }

    /** @return array<string, mixed> */
    private function payload(AgentJob $job): array
    {
        return [
            'id' => $job->id, 'kind' => $job->kind, 'status' => $job->status, 'input' => $job->input ?? [],
            'workspace_id' => $job->workspace_id, 'account_id' => $job->account_id, 'attempts' => $job->attempts,
            'engine_url' => rtrim((string) config('app.url'), '/'),
        ];
    }
}
