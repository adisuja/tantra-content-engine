<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Giveaway\GiveawayReplies;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Console\Command;

/** tantra: answer every new comment on giveaway posts during working hours (PRD 10.5 step 6). */
class GiveawayRepliesCommand extends Command
{
    protected $signature = 'tantra:giveaway-replies';

    protected $description = 'Reply to new comments on giveaway posts and add them to the DMs to send list';

    public function handle(UnipileClient $unipile, ZernioClient $zernio): int
    {
        if (! GiveawayReplies::enabled()) {
            $this->components->info('Auto-replies are switched off (TANTRA_AUTOREPLY_ENABLED).');

            return self::SUCCESS;
        }

        $this->components->info(GiveawayReplies::run($unipile, $zernio).' replies queued.');

        return self::SUCCESS;
    }
}
