<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\Account;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Ai\ConnectionTester;
use App\Tantra\Ai\ProviderCatalog;
use Illuminate\Console\Command;

/**
 * tantra: add or replace a connector from the server, reading the key from
 * STDIN so it never sits in shell history or passes through a browser.
 *
 *   printf %s "$KEY" | php artisan tantra:ai-connector cloudflare --account=<id> --base-url=... --default
 */
class AiConnectorCommand extends Command
{
    protected $signature = 'tantra:ai-connector {provider} {--account= : Account id (default: the only Account)}
        {--base-url=} {--model=} {--default : Make it the default AI provider}';

    protected $description = 'Add or replace a Connectors tab entry, key read from STDIN';

    public function handle(): int
    {
        $provider = (string) $this->argument('provider');
        if (ProviderCatalog::find($provider) === null) {
            $this->error('Unknown provider. One of: '.implode(', ', array_keys(ProviderCatalog::all())));

            return self::FAILURE;
        }

        $accountId = $this->option('account') ?: (Account::query()->count() === 1 ? Account::query()->value('id') : null);
        if ($accountId === null) {
            $this->error('Pass --account=<id>.');

            return self::FAILURE;
        }

        $secret = trim((string) stream_get_contents(STDIN));
        if ($secret === '') {
            $this->error('Pipe the key on STDIN.');

            return self::FAILURE;
        }

        $connector = AiConnector::query()->firstOrNew(['account_id' => $accountId, 'provider' => $provider]);
        $connector->fill([
            'secret' => $secret,
            'secret_last_four' => AiConnector::lastFour($secret),
            'base_url' => $this->option('base-url') ?: $connector->base_url,
            'selected_model' => $this->option('model') ?: $connector->selected_model,
            'is_active' => true,
        ]);

        $result = ConnectionTester::test($connector);
        $this->line(($result['ok'] ? 'ok: ' : 'FAILED: ').$result['message']);
        if (! $result['ok']) {
            return self::FAILURE;
        }

        $connector->last_verified_at = now();
        $connector->save();

        if ($this->option('default') && ProviderCatalog::isAi($provider)) {
            AiConnector::query()->where('account_id', $accountId)->update(['is_default' => false]);
            $connector->forceFill(['is_default' => true])->save();
        }

        $this->info("Saved {$provider} for Account {$accountId}.");

        return self::SUCCESS;
    }
}
