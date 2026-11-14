<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: the job queue (PRD 25.5). Enqueue fires the first enabled cloud task
 * account; a local runner can claim anything not taken. tick() (every minute)
 * re-fires unclaimed jobs, requeues stalled ones and purges old inputs.
 */
final class AgentJobs
{
    public const REDISPATCH_MINUTES = 10;

    public const MAX_DISPATCHES = 3;

    public const STALE_MINUTES = 30;

    public const MAX_ATTEMPTS = 2;

    public const PURGE_DAYS = 30;

    /**
     * @param  bool  $fire  false: queue it for a run that is already going (the weekly repurpose fires one run that
     *                      claims the rest), so a busy week stays under the cloud task's 30 runs an hour
     */
    public static function enqueue(string $kind, array $input, User $by, ?string $workspaceId = null, ?Model $subject = null, bool $fire = true): AgentJob
    {
        // Our Claude subscriptions are their owners' own: jobs anyone else starts wait for an admin's Run (review B1).
        $byAdmin = Admin::is($by);
        $job = AgentJob::query()->create([
            'kind' => $kind,
            'status' => $byAdmin ? AgentJob::QUEUED : AgentJob::AWAITING_ADMIN,
            'account_id' => $by->account_id,
            'workspace_id' => $workspaceId,
            'requested_by' => $by->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'input' => $input,
        ]);
        if ($byAdmin && $fire) {
            self::dispatch($job);
        } elseif ($byAdmin) {
            $job->appendLog('Queued for the run already working this batch.');
            $job->forceFill(['dispatches' => self::MAX_DISPATCHES])->save();
        } else {
            $job->appendLog('Started by '.$by->name.'; waiting for an admin to run it.');
            $job->save();
        }

        return $job->refresh();
    }

    /** An admin releases a waiting job to the runners. */
    public static function run(AgentJob $job, User $admin): void
    {
        if ($job->status !== AgentJob::AWAITING_ADMIN) {
            return;
        }
        $job->appendLog("Run by {$admin->name}.");
        $job->forceFill(['status' => AgentJob::QUEUED])->save();
        self::dispatch($job);
    }

    /** Fire a cloud task for the job, if one is set up. Without one, a local runner claims it. */
    public static function dispatch(AgentJob $job): bool
    {
        $account = ClaudeAccount::query()->where('kind', 'cloud_task')->where('enabled', true)
            ->when($job->claude_account_id, fn ($q) => $q->whereKey($job->claude_account_id))
            ->orderBy('created_at')->first();
        if ($account === null) {
            return false;
        }

        try {
            $fired = CloudTask::fire($account, self::fireText($job));
        } catch (Throwable $e) {
            $job->appendLog('Could not start a cloud run: '.$e->getMessage());
            $job->forceFill(['dispatches' => $job->dispatches + 1])->save();

            return false;
        }

        $job->appendLog("Started a cloud run on {$account->name}.");
        $job->forceFill([
            'status' => AgentJob::DISPATCHED, 'claude_account_id' => $account->id, 'dispatches' => $job->dispatches + 1,
            'dispatched_at' => now(), 'session_url' => $fired['session_url'] ?? $job->session_url,
        ])->save();

        return true;
    }

    public static function fireText(AgentJob $job): string
    {
        return "Tantra agent job {$job->id} ({$job->kind}). Engine: ".rtrim((string) config('app.url'), '/')
            .". Read it with: python3 kits/agent/job.py get {$job->id}";
    }

    /**
     * Claim the oldest waiting job, or the one job a cloud run was started for.
     * Atomic: a second run for the same job gets nothing (review B3).
     *
     * @param  list<string>|null  $kinds
     */
    public static function claim(AgentRunner $runner, ?array $kinds = null, ?string $jobId = null): ?AgentJob
    {
        $kinds = $kinds ?: $runner->kinds;
        $runner->forceFill(['last_seen_at' => now()])->save();

        return DB::transaction(function () use ($runner, $kinds, $jobId): ?AgentJob {
            $job = AgentJob::query()->whereIn('status', [AgentJob::QUEUED, AgentJob::DISPATCHED])
                ->when($jobId, fn ($q) => $q->whereKey($jobId))
                ->when($kinds && ! $jobId, fn ($q) => $q->whereIn('kind', $kinds))
                ->orderBy('created_at')->lockForUpdate()->first();
            if ($job === null) {
                return null;
            }
            $token = Str::random(48);
            $taken = AgentJob::query()->whereKey($job->id)->whereIn('status', [AgentJob::QUEUED, AgentJob::DISPATCHED])->update([
                'status' => AgentJob::RUNNING, 'runner_id' => $runner->id, 'claim_token' => $token,
                'attempts' => $job->attempts + 1, 'started_at' => now(), 'heartbeat_at' => now(),
                'claude_account_id' => $job->claude_account_id ?? $runner->claude_account_id,
            ]);
            if ($taken !== 1) {
                return null;
            }
            $job->refresh();
            $job->appendLog("Claimed by {$runner->name}.");
            $job->save();
            $job->setAttribute('claim_token', $token);

            return $job;
        });
    }

    public static function log(AgentJob $job, string $line, ?string $sessionUrl = null): void
    {
        $job->appendLog($line);
        $job->forceFill(['heartbeat_at' => now(), 'session_url' => $sessionUrl ?: $job->session_url])->save();
    }

    /**
     * Apply a result. A job already done answers with its summary and is not applied again.
     *
     * @param  array<string, mixed>  $result
     */
    public static function complete(AgentJob $job, array $result): string
    {
        return DB::transaction(function () use ($job, $result): string {
            $job = AgentJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->status === AgentJob::DONE) {
                return (string) $job->summary;
            }
            if ($job->status !== AgentJob::RUNNING) {
                throw new InvalidResult("This job is {$job->status}; it takes no result.");
            }
            $applier = Appliers::for($job->kind);
            if ($applier === null) {
                throw new InvalidResult("Nothing in the Engine applies {$job->kind} results yet.");
            }

            $summary = mb_substr($applier->apply($job, $result), 0, 500);
            $job->appendLog('Done: '.$summary);
            $job->forceFill([
                'status' => AgentJob::DONE, 'result' => $result, 'summary' => $summary, 'error' => null,
                'finished_at' => now(), 'purge_after' => now()->addDays(self::PURGE_DAYS),
            ])->save();

            return $summary;
        });
    }

    public static function fail(AgentJob $job, string $error): void
    {
        $job->appendLog('Failed: '.$error);
        $job->forceFill([
            'status' => AgentJob::FAILED, 'error' => mb_substr($error, 0, 4000), 'finished_at' => now(),
            'purge_after' => now()->addDays(self::PURGE_DAYS),
        ])->save();
    }

    public static function cancel(AgentJob $job): void
    {
        if ($job->isOpen()) {
            $job->appendLog('Cancelled.');
            $job->forceFill(['status' => AgentJob::CANCELLED, 'finished_at' => now(), 'purge_after' => now()->addDays(self::PURGE_DAYS)])->save();
        }
    }

    public static function retry(AgentJob $job): void
    {
        $job->appendLog('Queued again.');
        $job->forceFill([
            'status' => AgentJob::QUEUED, 'error' => null, 'runner_id' => null, 'claim_token' => null, 'finished_at' => null,
            'purge_after' => null, 'dispatches' => 0,
        ])->save();
        self::dispatch($job);
    }

    /** @return array{redispatched: int, requeued: int, failed: int, purged: int} */
    public static function tick(): array
    {
        $out = ['redispatched' => 0, 'requeued' => 0, 'failed' => 0, 'purged' => 0];

        // Never fired (no cloud account at the time, or the fire failed) or fired and not picked up.
        $waiting = AgentJob::query()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('status', AgentJob::QUEUED)->where('dispatches', '<', self::MAX_DISPATCHES))
            ->orWhere(fn ($w) => $w->where('status', AgentJob::DISPATCHED)->where('dispatched_at', '<', now()->subMinutes(self::REDISPATCH_MINUTES))))
            ->limit(20)->get();
        foreach ($waiting as $job) {
            if ($job->status === AgentJob::DISPATCHED && $job->dispatches >= self::MAX_DISPATCHES) {
                $job->appendLog('No cloud run picked this up; waiting for a local runner.');
                $job->forceFill(['status' => AgentJob::QUEUED])->save();

                continue;
            }
            $out['redispatched'] += self::dispatch($job) ? 1 : 0;
        }

        // A run that went quiet: one more go, then failed.
        foreach (AgentJob::query()->where('status', AgentJob::RUNNING)->where('heartbeat_at', '<', now()->subMinutes(self::STALE_MINUTES))->get() as $job) {
            if ($job->attempts < self::MAX_ATTEMPTS) {
                $job->appendLog('No word from the run for '.self::STALE_MINUTES.' minutes; queued again.');
                $job->forceFill(['status' => AgentJob::QUEUED, 'runner_id' => null, 'claim_token' => null, 'dispatches' => 0])->save();
                $out['requeued']++;
            } else {
                self::fail($job, 'The run went quiet twice.');
                $out['failed']++;
            }
        }

        // Inputs, results and logs go 30 days after a job ends; the summary stays (PRD 25.3).
        $out['purged'] = AgentJob::query()->whereNotNull('purge_after')->where('purge_after', '<', now())
            ->where(fn ($q) => $q->whereNotNull('input')->orWhereNotNull('result')->orWhereNotNull('log'))
            ->update(['input' => null, 'result' => null, 'log' => null]);

        return $out;
    }
}
