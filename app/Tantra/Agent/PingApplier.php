<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

/** tantra: the test job from the ops screen: proves a runner and its Claude account work end to end. */
final class PingApplier implements JobApplier
{
    public function apply(AgentJob $job, array $result): string
    {
        $who = trim((string) ($result['runner'] ?? ''));

        return 'Pong'.($who !== '' ? " from {$who}" : '').'.';
    }
}
