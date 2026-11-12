<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * tantra: the warnings the app banner shows: no AI connected, or a connector
 * whose last call or hourly check failed, with the provider and the reason.
 */
final class AiAlerts
{
    /** @return list<array{level: string, provider: string|null, label: string|null, reason: string, message: string, since: string|null}> */
    public static function forAccount(?string $accountId): array
    {
        if ($accountId === null) {
            return [];
        }

        return Cache::remember(self::cacheKey($accountId), 60, function () use ($accountId): array {
            $connectors = AiConnector::query()->where('account_id', $accountId)->where('is_active', true)->whereNotNull('secret')->get();
            $alerts = [];

            // "Nothing connected" is said where the AI is used and on the Connectors tab;
            // the banner is for a provider or integration that stopped working.
            foreach ($connectors as $c) {
                if ($c->last_failed_at === null || ($c->last_verified_at !== null && $c->last_verified_at->gt($c->last_failed_at))) {
                    continue;
                }
                ['reason' => $reason, 'message' => $message] = AiFailure::parseStored($c->last_error);
                $alerts[] = [
                    'level' => $c->is_default || ! ProviderCatalog::isAi($c->provider) ? 'error' : 'warning',
                    'provider' => $c->provider,
                    'label' => $c->catalog()['label'] ?? $c->provider,
                    'reason' => $reason,
                    'message' => Str::limit(trim($message), 220),
                    'since' => $c->last_failed_at->toIso8601String(),
                ];
            }

            return $alerts;
        });
    }

    public static function forget(string $accountId): void
    {
        Cache::forget(self::cacheKey($accountId));
    }

    private static function cacheKey(string $accountId): string
    {
        return 'tantra:ai-alerts:'.$accountId;
    }
}
