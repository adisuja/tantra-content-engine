<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Repurpose\WeeklyCadence;
use Illuminate\Console\Command;

/** tantra: Mondays: queue the next network for every post whose cadence week has come (PRD 25.10). */
class RepurposeCadenceCommand extends Command
{
    protected $signature = 'tantra:repurpose-cadence';

    protected $description = 'Queue this week\'s repurposed versions of published posts';

    public function handle(): int
    {
        $out = WeeklyCadence::run();
        $this->line("{$out['jobs']} posts queued for their next network.");

        return self::SUCCESS;
    }
}
