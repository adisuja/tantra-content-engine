<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Manual\ManualTasks;
use Illuminate\Console\Command;

/** tantra: time waiting manual tasks, mark due ones and tell their owner, expire old ones (PRD 12). */
class ManualTasksCommand extends Command
{
    protected $signature = 'tantra:manual-tasks';

    protected $description = 'Advance the X and personal poll tasks posted by hand';

    public function handle(): int
    {
        $result = ManualTasks::tick();
        $this->components->info("{$result['due']} due, {$result['expired']} expired.");

        return self::SUCCESS;
    }
}
