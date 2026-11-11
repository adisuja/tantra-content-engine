<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Analytics\CollectMetrics;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Console\Command;

/** tantra: the analytics pass (PRD 10.6). */
class CollectMetricsCommand extends Command
{
    protected $signature = 'tantra:collect-metrics';

    protected $description = 'Read the numbers of published LinkedIn posts and the Tantra page';

    public function handle(ZernioClient $zernio, UnipileClient $unipile): int
    {
        $c = CollectMetrics::run($zernio, $unipile);
        $this->info("{$c['posts']} posts read, {$c['skipped']} skipped, {$c['accounts']} page days saved.");

        return self::SUCCESS;
    }
}
