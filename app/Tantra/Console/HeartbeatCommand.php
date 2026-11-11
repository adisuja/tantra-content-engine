<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Ops\Health;
use App\Tantra\Ops\MediaCheck;
use Illuminate\Console\Command;

/** tantra: every minute, the scheduler's tick for the health report; every 15 minutes, the media-exists check. */
class HeartbeatCommand extends Command
{
    protected $signature = 'tantra:heartbeat {--media : also run the media-exists check}';

    protected $description = 'Record the scheduler tick (and check scheduled media with --media)';

    public function handle(): int
    {
        Health::tick();
        if ($this->option('media')) {
            $this->info(MediaCheck::run().' scheduled posts are missing a file.');
        }

        return self::SUCCESS;
    }
}
