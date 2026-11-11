<?php

declare(strict_types=1);

namespace App\Tantra\Ops;

/**
 * Folds upstream's Horizon supervisors into one small worker pool.
 *
 * Upstream runs seven supervisors with at least one worker each (and up to 20
 * in production), sized for TryPost's own cloud. Every supervisor and worker
 * is a PHP process of 40 to 80 MB, which does not fit a 2 GB server. For a
 * team of a few people one pool works every queue, publishing first.
 *
 * Supervisors whose name starts with "tantra-" (our own, such as the boost
 * queue) are kept as they are, so they never wait behind upstream work.
 */
final class LeanHorizon
{
    public const SUPERVISOR = 'tantra-lean';

    /**
     * @param  array<string, array<string, mixed>>  $defaults  config('horizon.defaults')
     * @param  list<string>  $priorityQueues  queues to put first (social publishing)
     * @return array<string, array<string, mixed>>
     */
    public static function plan(array $defaults, array $priorityQueues, int $processes): array
    {
        $kept = [];
        $queues = $priorityQueues;
        $timeout = 60;
        $memory = 128;

        foreach ($defaults as $name => $supervisor) {
            if (str_starts_with((string) $name, 'tantra-')) {
                $kept[$name] = $supervisor;

                continue;
            }

            $queues = [...$queues, ...(array) ($supervisor['queue'] ?? [])];
            $timeout = max($timeout, (int) ($supervisor['timeout'] ?? 60));
            $memory = max($memory, (int) ($supervisor['memory'] ?? 128));
        }

        return [
            self::SUPERVISOR => [
                'connection' => 'redis',
                'queue' => array_values(array_unique($queues)),
                // false: a fixed number of workers, each taking queues in the
                // order listed, so a publish never waits behind analytics.
                'balance' => false,
                'maxProcesses' => max(1, $processes),
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => $memory,
                'tries' => 1,
                'timeout' => $timeout,
                'nice' => 0,
            ],
            ...$kept,
        ];
    }
}
