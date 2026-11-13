<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Channels\TeamChannels;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Console\Command;

/** tantra: brings every account connected in the Tantra app (Unipile and Tantra API keys) into the Engine as channels. */
class SyncChannelsCommand extends Command
{
    protected $signature = 'tantra:sync-channels
        {--create-workspaces : Give a listed or named person with no workspace their own (named after their first name)}
        {--person=* : A teammate to bring in by their exact LinkedIn name, even with no Engine user or Tantra API key}';

    protected $description = 'Bring every account connected in Tantra into the Engine as channels';

    public function handle(UnipileClient $unipile): int
    {
        $out = TeamChannels::sync($unipile, (bool) $this->option('create-workspaces'), array_values(array_filter((array) $this->option('person'), 'is_string')));

        $this->line(sprintf('created %d, updated %d, kept the direct LinkedIn %d, from Tantra API keys %d', $out['created'], $out['updated'], $out['skipped'], $out['tantra_api']));
        foreach ($out['workspaces_created'] as $name) {
            $this->line("new workspace: {$name}");
        }
        foreach ($out['unplaced'] as $name) {
            $this->warn("no workspace for: {$name}");
        }
        foreach ($out['errors'] as $error) {
            $this->error($error);
        }

        return $out['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
