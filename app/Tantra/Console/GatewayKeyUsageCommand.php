<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\AccessToken;
use Illuminate\Console\Command;

/**
 * tantra: lists the engine's own personal API keys with when each was last used, so the A-01 deprecation rule can be read:
 * the keys stop working only after 14 days with no traffic (docs/crm/PRD_ONE_API_AND_MCP.md, P4). Read only.
 */
class GatewayKeyUsageCommand extends Command
{
    protected $signature = 'tantra:gateway-key-usage
        {--idle=14 : Days without use that count as idle}
        {--json : Print JSON}';

    protected $description = 'List engine API keys by last use, and which have had no traffic for the idle window';

    public function handle(): int
    {
        $idle = max(1, (int) $this->option('idle'));
        $rows = AccessToken::query()
            ->where('revoked', false)
            ->get()
            ->filter(fn (AccessToken $t) => $t->isPersonalAccessToken())
            ->map(fn (AccessToken $t) => [
                'name' => (string) $t->name,
                'workspace' => (string) $t->workspace_id,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'idle_days' => $t->last_used_at === null ? null : (int) $t->last_used_at->diffInDays(now()),
                'idle' => $t->last_used_at === null || $t->last_used_at->lt(now()->subDays($idle)),
            ])
            ->values();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->table(['Key', 'Workspace', 'Last used', 'Idle days', 'Idle'], $rows->map(fn (array $r) => [$r['name'], $r['workspace'], $r['last_used_at'] ?? 'never', $r['idle_days'] ?? '-', $r['idle'] ? 'yes' : 'no'])->all());
        $this->info($rows->where('idle', false)->count().' key(s) used in the last '.$idle.' days; '.$rows->where('idle', true)->count().' idle.');

        return self::SUCCESS;
    }
}
