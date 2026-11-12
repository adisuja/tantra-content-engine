<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Laravel\Ai\Exceptions\AiException;

/**
 * tantra: every connector in the chain failed (or none can do the job). Extends
 * AiException so the upstream controllers that already catch it keep working;
 * the payload names each provider and why, without the provider's raw text
 * (that stays on the Connectors tab for the Account owner).
 */
final class AiUnavailableException extends AiException
{
    public const PROVIDERS_FAILED = 'providers_failed';

    public const NONE_CONFIGURED = 'none_configured';

    public const NO_IMAGE_PROVIDER = 'no_image_provider';

    /** @param  list<AiFailure>  $failures */
    public function __construct(public readonly array $failures, public readonly string $reason = self::PROVIDERS_FAILED)
    {
        parent::__construct($this->summary());
    }

    public function summary(): string
    {
        return match ($this->reason) {
            self::NONE_CONFIGURED => 'No AI provider is connected. Add one under Settings, Connectors.',
            self::NO_IMAGE_PROVIDER => 'None of your connected AI providers can read images. Add Gemini, OpenAI or the Anthropic API under Settings, Connectors.',
            default => implode(' ', array_map(fn (AiFailure $f): string => $f->message(), $this->failures)).' Manage providers under Settings, Connectors.',
        };
    }

    /** @return array{message: string, reason: string, ai_failures: list<array<string, string>>, connectors_url: string} */
    public function payload(): array
    {
        return [
            'message' => $this->summary(),
            'reason' => $this->reason,
            'ai_failures' => array_map(fn (AiFailure $f): array => $f->toPublicArray(), $this->failures),
            'connectors_url' => '/settings/workspace/connectors',
        ];
    }
}
