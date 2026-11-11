<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Channels\ReconnectReminders;
use Illuminate\Console\Command;

/** tantra: the daily reconnect reminders (PRD M3). */
class ReconnectRemindersCommand extends Command
{
    protected $signature = 'tantra:reconnect-reminders';

    protected $description = 'Remind review owners to reconnect LinkedIn before it runs out, or once it drops';

    public function handle(): int
    {
        $this->info(ReconnectReminders::run().' reminders sent.');

        return self::SUCCESS;
    }
}
