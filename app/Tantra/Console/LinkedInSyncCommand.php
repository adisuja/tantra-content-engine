<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Boost\MemberProfile;
use App\Tantra\LinkedIn\LinkedInSync;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Console\Command;

/**
 * tantra: match new LinkedIn accounts in Unipile to teammates and pull each
 * person's name, headline, photo and profile from LinkedIn, and the Tantra
 * page's from Zernio. Every 15 minutes; an identity refreshes once a day.
 */
class LinkedInSyncCommand extends Command
{
    protected $signature = 'tantra:linkedin-sync {--force : read everyone now, not only those due}';

    protected $description = 'Pull names, headlines and photos from LinkedIn (Unipile and Zernio)';

    public function handle(UnipileClient $unipile, ZernioClient $zernio): int
    {
        $out = LinkedInSync::run($unipile, $zernio, force: (bool) $this->option('force'));
        $this->line("matched {$out['matched']}, people read {$out['people']}, disconnected {$out['disconnected']}, pages {$out['pages']}");

        if ($this->output->isVerbose() || $this->option('force')) {
            $this->table(['Person', 'LinkedIn name', 'Headline', 'Photo', 'Unipile', 'Read at'], MemberProfile::query()->with('user')->whereNotNull('unipile_account_id')->get()
                ->map(fn (MemberProfile $p): array => [
                    $p->user?->name, $p->linkedin_name, (string) str($p->linkedin_headline)->limit(50),
                    $p->linkedin_picture_path ? 'yes' : 'no', $p->unipile_status, $p->linkedin_synced_at?->toDateTimeString(),
                ])->all());
        }

        return self::SUCCESS;
    }
}
