<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * tantra: "Test connection" on the Connectors tab. AI connectors answer a
 * one-word prompt through the same path every feature uses (so a passing test
 * means the features will work); the Tantra API answers a campaigns read.
 */
final class ConnectionTester
{
    /** @return array{ok: bool, latency_ms: int, message: string, failure: array<string, string>|null, models_available: int|null} */
    public static function test(AiConnector $connector): array
    {
        $started = hrtime(true);

        try {
            EndpointGuard::check($connector->effectiveBaseUrl());
            if (filled($connector->catalog()['driver'] ?? null) && blank($connector->effectiveModel())) {
                throw new RuntimeException('Pick a model first.');
            }

            if ($connector->provider === 'tantra_api') {
                Http::timeout(15)->withoutRedirecting()->acceptJson()->withHeaders(['X-API-Key' => trim((string) $connector->secret)])
                    ->get($connector->effectiveBaseUrl().'/campaigns', ['limit' => 1])->throw();
                $message = 'Connected to the Tantra API.';
            } else {
                $answer = TantraAi::withoutRedirects(fn () => (new PingAgent)->prompt(
                    'Reply now.',
                    provider: TantraAi::register($connector),
                    model: $connector->effectiveModel(),
                    timeout: $connector->isSubscription() ? TantraAi::CLI_TIMEOUT : 45,
                ));
                $message = 'Connected. '.$connector->catalog()['label'].' answered: '.Str::limit(trim((string) ($answer['status'] ?? $answer)), 40);
            }
        } catch (Throwable $e) {
            $failure = AiFailure::from($connector->provider, $e);

            return ['ok' => false, 'latency_ms' => self::since($started), 'message' => $failure->message(), 'failure' => $failure->toArray(), 'models_available' => null];
        }

        return ['ok' => true, 'latency_ms' => self::since($started), 'message' => $message, 'failure' => null, 'models_available' => null];
    }

    /**
     * The models the provider offers, for the model picker. Empty when the
     * provider has no list endpoint (the field is free text then).
     *
     * @return list<array{id: string, free: bool}>
     */
    public static function models(AiConnector $connector): array
    {
        $path = $connector->catalog()['models_path'] ?? null;
        $base = $connector->effectiveBaseUrl();
        if ($path === null || blank($base) || str_contains($base, '{') || blank($connector->secret)) {
            return [];
        }

        try {
            EndpointGuard::check($base);
        } catch (RuntimeException) {
            return [];
        }

        $request = Http::timeout(15)->withoutRedirecting()->acceptJson();
        $request = match ($connector->provider) {
            'anthropic' => $request->withHeaders(['x-api-key' => $connector->secret, 'anthropic-version' => '2023-06-01']),
            default => $request->withToken(trim((string) $connector->secret)),
        };

        try {
            $data = (array) $request->get($base.$path)->throw()->json('data', []);
        } catch (RequestException|ConnectionException) {
            return [];
        }

        return collect($data)->map(fn ($m): array => [
            'id' => (string) data_get($m, 'id'),
            'free' => Str::endsWith((string) data_get($m, 'id'), ':free') || (string) data_get($m, 'pricing.prompt') === '0',
        ])->filter(fn (array $m): bool => $m['id'] !== '')->sortBy('id')->take(400)->values()->all();
    }

    private static function since(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
