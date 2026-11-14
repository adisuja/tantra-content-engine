<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Agent\AgentJobs;
use Illuminate\Console\Command;

/** tantra: every minute: re-fire unclaimed agent jobs, requeue quiet ones, purge old inputs (PRD 25.5). */
class AgentJobsCommand extends Command
{
    protected $signature = 'tantra:agent-jobs';

    protected $description = 'Keep agent jobs moving: re-fire, requeue, purge';

    public function handle(): int
    {
        $out = AgentJobs::tick();
        if (array_sum($out) > 0) {
            $this->line(collect($out)->map(fn (int $n, string $k): string => "{$k} {$n}")->implode(', '));
        }

        return self::SUCCESS;
    }
}
