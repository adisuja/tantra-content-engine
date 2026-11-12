<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * tantra: why one connector failed, in words a person can act on. Shown where
 * the AI was used, on the connector's card and in the app banner.
 */
final class AiFailure
{
    public const NO_CREDITS = 'no_credits';

    public const RATE_LIMITED = 'rate_limited';

    public const BAD_KEY = 'bad_key';

    public const BAD_MODEL = 'bad_model';

    public const UNREACHABLE = 'unreachable';

    public const OVERLOADED = 'overloaded';

    public const SIGNED_OUT = 'signed_out';

    public const OTHER = 'other';

    public function __construct(
        public readonly string $provider,
        public readonly string $label,
        public readonly string $reason,
        public readonly string $detail,
    ) {}

    public static function from(string $provider, Throwable $e): self
    {
        $label = ProviderCatalog::find($provider)['label'] ?? ($provider === 'server' ? 'The built-in AI provider' : $provider);
        // laravel/ai wraps the HTTP error; the provider's own words are in the previous exception.
        $status = null;
        $text = '';
        $own = '';
        for ($x = $e, $depth = 0; $x !== null && $depth < 5; $x = $x->getPrevious(), $depth++) {
            $text .= ' '.$x->getMessage();
            if ($x instanceof RequestException) {
                $status ??= $x->response->status();
                $text .= ' '.$x->response->body();
                if ($own === '') {
                    $candidate = collect([$x->response->json('error.message'), $x->response->json('message'), $x->response->json('error'), $x->response->json('errors.0.message')])
                        ->first(fn ($v): bool => is_string($v) && $v !== '');
                    $own = is_string($candidate) ? $candidate : '';
                }
            }
        }
        $text = mb_strtolower($text);
        $isEndpointRefusal = str_contains($text, 'private network, which the engine does not call') || str_contains($text, 'must be an http or https address');

        $reason = match (true) {
            $isEndpointRefusal => self::UNREACHABLE,
            $e instanceof InsufficientCreditsException, $status === 402,
            str_contains($text, 'insufficient') && (str_contains($text, 'credit') || str_contains($text, 'quota') || str_contains($text, 'balance')),
            str_contains($text, 'exceeded your current quota') && ($provider !== 'gemini' || str_contains($text, 'perday') || str_contains($text, 'per day')),
            str_contains($text, 'out of credits'),
            str_contains($text, 'neurons') && str_contains($text, 'limit'),
            str_contains($text, 'usage limit'),
            str_contains($text, 'rate limit') && (str_contains($text, 'per day') || str_contains($text, '(tpd)') || str_contains($text, '(rpd)') || str_contains($text, 'daily')) => self::NO_CREDITS,
            $e instanceof RateLimitedException, $status === 429, str_contains($text, 'rate limit') => self::RATE_LIMITED,
            $e instanceof ProviderOverloadedException, $status === 503, $status === 529, str_contains($text, 'overloaded') => self::OVERLOADED,
            str_contains($text, 'setup-token'), str_contains($text, 'not logged in'), str_contains($text, 'please run /login'),
            str_contains($text, 'oauth token has expired'), str_contains($text, 'refresh token'), str_contains($text, 'refresh_token'),
            str_contains($text, 'oauth access token is invalid'), str_contains($text, 'failed to authenticate'), str_contains($text, 'token_expired'),
            str_contains($text, 'not signed in'), str_contains($text, 'please log in'), str_contains($text, 'codex login') => self::SIGNED_OUT,
            $status === 401, $status === 403, str_contains($text, 'invalid api key'), str_contains($text, 'invalid x-api-key'),
            str_contains($text, 'unauthorized'), str_contains($text, 'authentication'), str_contains($text, 'api key not valid'),
            str_contains($text, 'api_key_invalid'), str_contains($text, 'invalid_api_key'), str_contains($text, 'incorrect api key'),
            str_contains($text, 'permission_denied'), str_contains($text, 'no auth credentials') => self::BAD_KEY,
            str_contains($text, 'response_format'), str_contains($text, 'json_schema'), str_contains($text, 'structured output'),
            str_contains($text, 'requires a default text model'),
            $status === 404, str_contains($text, 'model') && (str_contains($text, 'not found') || str_contains($text, 'does not exist') || str_contains($text, 'not supported')) => self::BAD_MODEL,
            $e instanceof ConnectionException, str_contains($text, 'could not resolve'), str_contains($text, 'timed out'),
            str_contains($text, 'connection refused'), str_contains($text, 'not installed') => self::UNREACHABLE,
            default => self::OTHER,
        };

        $detail = is_string($own) && $own !== '' ? $own : $e->getMessage();

        return new self($provider, $label, $reason, mb_substr(trim(preg_replace('/\s+/', ' ', $detail) ?? ''), 0, 300));
    }

    /** One line for the person using the feature, e.g. "OpenRouter is out of credits." */
    public function message(): string
    {
        return match ($this->reason) {
            self::NO_CREDITS => "{$this->label} is out of credits or has used its free allowance.",
            self::RATE_LIMITED => "{$this->label} hit its rate limit. Try again in a minute.",
            self::OVERLOADED => "{$this->label} is overloaded right now.",
            self::SIGNED_OUT => "{$this->label} is signed out. Paste a fresh sign-in on the Connectors tab.",
            self::BAD_KEY => "{$this->label} rejected the key. Check it on the Connectors tab.",
            self::BAD_MODEL => "{$this->label} cannot run the chosen model, or the model cannot return the structured answers the features need. Pick another on the Connectors tab.",
            self::UNREACHABLE => "{$this->label} could not be reached, or its endpoint is not allowed.",
            default => "{$this->label} returned an error.",
        };
    }

    /** What the database keeps: reason, the one-line message, then the provider's words. */
    public function toStored(): string
    {
        return mb_substr($this->reason.'|'.$this->message()."\n".$this->detail, 0, 500);
    }

    /** @return array{reason: string, message: string, detail: string} */
    public static function parseStored(?string $stored): array
    {
        [$head, $detail] = array_pad(explode("\n", (string) $stored, 2), 2, '');
        [$reason, $message] = array_pad(explode('|', $head, 2), 2, '');

        return ['reason' => $reason !== '' ? $reason : self::OTHER, 'message' => trim($message), 'detail' => trim($detail)];
    }

    /** @return array{provider: string, label: string, reason: string, message: string} */
    public function toPublicArray(): array
    {
        return ['provider' => $this->provider, 'label' => $this->label, 'reason' => $this->reason, 'message' => $this->message()];
    }

    /** @return array{provider: string, label: string, reason: string, message: string, detail: string} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'reason' => $this->reason,
            'message' => $this->message(),
            'detail' => $this->detail,
        ];
    }
}
