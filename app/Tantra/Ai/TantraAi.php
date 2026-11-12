<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * tantra: every AI call in the app goes through here. It runs the agent on the
 * Account's default connector, then on each other AI connector in turn, and
 * records why each one failed so the user sees it where they used the AI and
 * on the Connectors tab. With no connector it behaves exactly as upstream
 * TryPost (the server's .env provider), except that a server with no AI at
 * all now says so and points to the Connectors tab.
 */
final class TantraAi
{
    /** Stop trying further connectors after this long, inside the 120s proxy limit. */
    private const CHAIN_BUDGET_SECONDS = 85;

    /** Seconds a CLI (Claude Code, Codex) may take for one answer. */
    public const CLI_TIMEOUT = 80;

    /** A connector that failed this recently is tried after the others. */
    private const RECENT_FAILURE_MINUTES = 15;

    /**
     * @param  array<int, mixed>  $attachments
     *
     * @throws AiUnavailableException
     */
    public static function prompt(Agent $agent, string $prompt, ?string $accountId, array $attachments = []): AgentResponse
    {
        $needsImages = $attachments !== [];
        $all = self::chain($accountId);
        $chain = $needsImages ? $all->filter(fn (AiConnector $c): bool => (bool) ($c->catalog()['supports_images'] ?? false))->values() : $all;

        if ($chain->isEmpty()) {
            if ($all->isNotEmpty() && $needsImages) {
                throw new AiUnavailableException([], reason: AiUnavailableException::NO_IMAGE_PROVIDER);
            }

            if (self::serverProviderConfigured()) {
                return $agent->prompt($prompt, $attachments); // upstream behaviour, upstream errors
            }

            try {
                return $agent->prompt($prompt, $attachments); // a test fake still answers
            } catch (AiException|RequestException|ConnectionException) {
                throw new AiUnavailableException([], reason: AiUnavailableException::NONE_CONFIGURED);
            }
        }

        $failures = [];
        $started = microtime(true);

        foreach ($chain as $connector) {
            $remaining = (int) floor(self::CHAIN_BUDGET_SECONDS - (microtime(true) - $started));
            if ($failures !== [] && $remaining < 10) {
                break;
            }

            try {
                EndpointGuard::check($connector->effectiveBaseUrl());
                $response = self::withoutRedirects(fn (): AgentResponse => $agent->prompt(
                    $prompt,
                    $attachments,
                    provider: self::register($connector),
                    model: $connector->effectiveModel(),
                    timeout: min($connector->isSubscription() ? self::CLI_TIMEOUT : 60, $remaining),
                ));
                self::recordSuccess($connector);

                return $response;
            } catch (Throwable $e) {
                $failure = AiFailure::from($connector->provider, $e);
                self::recordFailure($connector, $failure);
                $failures[] = $failure;
            }
        }

        throw new AiUnavailableException($failures);
    }

    /**
     * The default AI connector first, then the other ones, most recently
     * verified first; anything that failed in the last few minutes goes last.
     *
     * @return Collection<int, AiConnector>
     */
    public static function chain(?string $accountId): Collection
    {
        if ($accountId === null) {
            return collect();
        }

        $recent = now()->subMinutes(self::RECENT_FAILURE_MINUTES);

        return AiConnector::query()->forAi()->where('account_id', $accountId)->whereNotNull('secret')->get()
            ->sortBy(fn (AiConnector $c): array => [
                self::failingSince($c, $recent) ? 1 : 0,
                $c->is_default ? 0 : 1,
                $c->last_verified_at === null ? 1 : 0,
                -($c->last_verified_at?->getTimestamp() ?? 0),
            ])
            ->values();
    }

    /** Puts the connector into config('ai.providers') and returns its name. */
    public static function register(AiConnector $connector): string
    {
        $name = filled($connector->id) ? $connector->providerName() : 'tantra_test_'.$connector->provider;
        $catalog = $connector->catalog();

        config(["ai.providers.{$name}" => array_filter([
            'driver' => $catalog['driver'],
            'key' => $connector->isSubscription() ? null : $connector->secret,
            'url' => $connector->effectiveBaseUrl(),
            'connector_id' => filled($connector->id) ? $connector->id : null,
            'cli_secret' => $connector->isSubscription() ? $connector->secret : null,
            'models' => ['text' => ['default' => $connector->effectiveModel()]],
        ], fn ($v) => $v !== null)]);

        Ai::forgetInstance($name);

        return $name;
    }

    /**
     * A connector's endpoint answers only for itself: a redirect to another
     * host (say, the cloud metadata address) is not followed.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function withoutRedirects(callable $call): mixed
    {
        $factory = Http::getFacadeRoot();
        $previous = (fn (): array => $this->globalOptions)->call($factory);
        Http::globalOptions(array_merge($previous, ['allow_redirects' => false]));

        try {
            return $call();
        } finally {
            Http::globalOptions($previous);
        }
    }

    /** True when the server's .env gives the default provider a way to authenticate. */
    public static function serverProviderConfigured(): bool
    {
        $default = (string) config('ai.default');
        $provider = (array) config("ai.providers.{$default}", []);

        return match ($provider['driver'] ?? $default) {
            'ollama' => filled($provider['url'] ?? null),
            'bedrock' => filled($provider['key'] ?? null) || filled($provider['access_key_id'] ?? null) || (bool) ($provider['use_default_credential_provider'] ?? false),
            default => filled($provider['key'] ?? null),
        };
    }

    public static function recordSuccess(AiConnector $connector): void
    {
        if ($connector->exists) {
            $connector->forceFill(['last_verified_at' => now(), 'last_failed_at' => null, 'last_error' => null])->saveQuietly();
            AiAlerts::forget($connector->account_id);
        }
    }

    public static function recordFailure(AiConnector $connector, AiFailure $failure): void
    {
        if ($connector->exists) {
            $connector->forceFill(['last_failed_at' => now(), 'last_error' => $failure->toStored()])->saveQuietly();
            AiAlerts::forget($connector->account_id);
        }
    }

    private static function failingSince(AiConnector $c, \DateTimeInterface $since): bool
    {
        return $c->last_failed_at !== null && $c->last_failed_at->gt($since)
            && ($c->last_verified_at === null || $c->last_failed_at->gt($c->last_verified_at));
    }
}
