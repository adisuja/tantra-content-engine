<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Notify\CrmMailFlags;
use App\Tantra\Ops\Health;
use App\Tantra\Ops\MediaCheck;
use Illuminate\Console\Command;

/**
 * tantra: every minute, the scheduler's tick for the health report; every 15 minutes, the media-exists check. Every
 * 5 minutes it also reads the main app's mail flags, so the main app always knows what this Engine can send (its
 * cutover plan reads the X-Tantra-Engine-Emits header), even when no email has gone out since a deploy (O-13).
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'tantra:heartbeat {--media : also run the media-exists check}';

    protected $description = 'Record the scheduler tick (and check scheduled media with --media)';

    public function handle(): int
    {
        Health::tick();
        if (! $this->option('media') && now()->minute % 5 === 0) {
            CrmMailFlags::all();
        }
        if ($this->option('media')) {
            $this->info(MediaCheck::run().' scheduled posts are missing a file.');
        }

        return self::SUCCESS;
    }
}
