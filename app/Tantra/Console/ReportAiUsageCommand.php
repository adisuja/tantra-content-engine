<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Ai\AiUsage;
use Illuminate\Console\Command;

/**
 * tantra: every five minutes, the engine's AI calls go to Tantra's AI cost ledger (A-05b), so the AI tasks panel and
 * the AI costs report show them. Needs TANTRA_CRM_API_KEY; TANTRA_CRM_AI_USAGE=false turns it off.
 */
class ReportAiUsageCommand extends Command
{
    protected $signature = 'tantra:report-ai-usage {--limit=200 : Rows to send in this run}';

    protected $description = 'Send recorded AI calls to Tantra\'s AI cost ledger';

    public function handle(): int
    {
        if (! AiUsage::reporting()) {
            $this->line('AI usage reporting is off: TANTRA_CRM_API_KEY is not set, or TANTRA_CRM_AI_USAGE=false.');

            return self::SUCCESS;
        }

        $out = AiUsage::report(max(1, (int) $this->option('limit')));
        $pruned = AiUsage::prune();
        $this->line(sprintf('Sent %d, refused %d, left for the next run %d, removed %d old rows.', $out['sent'], $out['refused'], $out['left'], $pruned));

        return self::SUCCESS;
    }
}
