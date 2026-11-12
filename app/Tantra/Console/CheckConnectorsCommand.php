<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Ai\AiAlerts;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Ai\AiFailure;
use App\Tantra\Ai\ConnectionTester;
use App\Tantra\Ai\TantraAi;
use Illuminate\Console\Command;

/**
 * tantra: the hourly check behind the Connectors tab's status and the app
 * banner. A connector that ran out of credits, lost its sign-in or stopped
 * answering is flagged before someone hits it in the composer.
 */
class CheckConnectorsCommand extends Command
{
    protected $signature = 'tantra:check-connectors {--account= : Only this Account}';

    protected $description = 'Test every active connector and record which ones fail and why';

    public function handle(): int
    {
        $connectors = AiConnector::query()->where('is_active', true)->whereNotNull('secret')
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))->get();

        foreach ($connectors as $connector) {
            $result = ConnectionTester::test($connector);
            if ($result['ok']) {
                TantraAi::recordSuccess($connector);
            } else {
                $f = $result['failure'];
                TantraAi::recordFailure($connector, new AiFailure($f['provider'], $f['label'], $f['reason'], $f['detail']));
            }
            AiAlerts::forget($connector->account_id);
            $this->line(sprintf('%-12s %-14s %s', $result['ok'] ? 'ok' : 'FAILED', $connector->provider, $result['message']));
        }

        return self::SUCCESS;
    }
}
