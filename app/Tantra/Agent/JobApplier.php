<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

/**
 * tantra: turns a job's result into Engine records (a template, ideas, review
 * items, a rebrand proposal). Must be idempotent: a result applied twice makes
 * the same records once (PRD 25.5).
 */
interface JobApplier
{
    /**
     * Check the result and apply it. Throw InvalidResult when its shape is wrong.
     *
     * @param  array<string, mixed>  $result
     * @return string a one-line summary kept after the job is purged
     */
    public function apply(AgentJob $job, array $result): string;
}
