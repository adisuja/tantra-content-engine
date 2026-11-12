<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * tantra: the prompt "Test connection" sends. It asks for a structured answer,
 * as Generate ideas and team threads do, so a model that cannot return JSON
 * fails the test instead of failing later in the composer.
 */
final class PingAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a connection check. Answer with status set to the word OK.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->required()];
    }
}
