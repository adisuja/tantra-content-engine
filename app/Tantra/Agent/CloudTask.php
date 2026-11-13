<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * tantra: starts a Claude Code cloud scheduled task through its API trigger,
 * one run per job (PRD 25.3). The run reads the job from the Engine with its
 * runner token, does the work and posts the result back.
 */
final class CloudTask
{
    /** @return array{session_url: ?string} */
    public static function fire(ClaudeAccount $account, string $text): array
    {
        if ($account->kind !== 'cloud_task' || blank($account->fire_url) || blank($account->secret)) {
            throw new RuntimeException("{$account->name} is not a cloud task with a fire URL and token.");
        }

        try {
            $response = Http::timeout(30)
                ->withToken((string) $account->secret)
                ->withHeaders(['anthropic-beta' => 'experimental-cc-routine-2026-04-01', 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->post((string) $account->fire_url, ['text' => $text]);
        } catch (Throwable $e) {
            throw new RuntimeException('The cloud task could not be reached: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $why = (string) ($response->json('error.message') ?? $response->json('message') ?? $response->body());
            throw new RuntimeException("The cloud task refused the run (HTTP {$response->status()}): ".mb_substr($why, 0, 200));
        }

        $url = $response->json('session_url') ?? $response->json('url') ?? $response->json('session.url');
        if ($url === null && is_string($id = $response->json('session_id') ?? $response->json('id'))) {
            $url = "https://claude.ai/code/{$id}";
        }

        return ['session_url' => is_string($url) ? $url : null];
    }
}
