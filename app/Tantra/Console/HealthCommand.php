<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Ops\Health;
use Illuminate\Console\Command;

/** tantra: `tantra:health` prints the health report as JSON and fails when something is wrong (PRD 8.5). */
class HealthCommand extends Command
{
    protected $signature = 'tantra:health';

    protected $description = 'Print the Engine health report as JSON';

    public function handle(): int
    {
        $report = Health::report();
        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
