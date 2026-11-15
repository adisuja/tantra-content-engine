<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\TextResponse;
use Throwable;

/**
 * tantra: the AI cost ledger for the engine (A-05b). Every AI call records one row: the task key from Tantra's model
 * registry (content.assist, content.shorten, ...), the provider and the model that answered, and the tokens. The rows
 * go to Tantra's ledger (POST {TANTRA_CRM_URL}/api/ai/usage with the server key TANTRA_CRM_API_KEY), so the AI tasks
 * panel and GET /api/billing/ai-usage show the engine's calls. Tantra prices the model; the engine sends no price.
 *
 * Recording never throws and never slows the call: a failed write is dropped.
 */
final class AiUsage
{
    /** The task keys Tantra's registry knows for the engine (crm-worker src/ai/registry.ts). */
    public const TASKS = ['content.assist', 'content.shorten', 'content.idea', 'content.alt_text', 'content.thread', 'content.ping', 'content.agent'];

    private const TABLE = 'tantra_ai_usages';

    /** Rows sent per run of tantra:report-ai-usage. */
    public const BATCH = 200;

    public static function record(string $task, ?string $accountId, string $provider, ?string $model, mixed $response = null, ?string $error = null): void
    {
        if (! in_array($task, self::TASKS, true)) {
            return;
        }

        try {
            $usage = $response instanceof TextResponse ? $response->usage : null;
            $served = $response instanceof TextResponse && filled($response->meta->model ?? null) ? $response->meta->model : $model;
            DB::table(self::TABLE)->insert([
                'id' => (string) Str::uuid(),
                'account_id' => $accountId,
                'task' => $task,
                'provider' => Str::limit($provider, 40, ''),
                'model' => $served === null ? null : Str::limit((string) $served, 120, ''),
                'input_tokens' => $usage?->promptTokens,
                'output_tokens' => $usage?->completionTokens,
                'cache_read_tokens' => $usage?->cacheReadInputTokens,
                'cache_write_tokens' => $usage?->cacheWriteInputTokens,
                'outcome' => $error === null ? 'ok' : 'error',
                'error' => $error === null ? null : Str::limit($error, 200, ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // A ledger write never breaks the feature.
        }
    }

    /** True when the server can send usage to Tantra. */
    public static function reporting(): bool
    {
        return (bool) config('tantra.crm.ai_usage', true) && filled(config('tantra.crm.api_key'));
    }

    /**
     * Send unsent rows to Tantra. Each row is its own report, keyed by the row id, so a repeat is ignored there. A 2xx or
     * a 4xx (a row Tantra will never take) closes the row; a 5xx or no answer leaves it for the next run.
     *
     * @return array{sent: int, refused: int, left: int}
     */
    public static function report(int $limit = self::BATCH): array
    {
        $out = ['sent' => 0, 'refused' => 0, 'left' => 0];
        if (! self::reporting()) {
            return $out;
        }

        $rows = DB::table(self::TABLE)->whereNull('reported_at')->orderBy('created_at')->limit($limit)->get();
        foreach ($rows as $row) {
            try {
                $response = self::send($row);
            } catch (Throwable) {
                $out['left']++;

                continue;
            }

            if ($response->successful() || $response->clientError()) {
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'reported_at' => now(),
                    'report_error' => $response->successful() ? null : Str::limit('HTTP '.$response->status().' '.(string) ($response->json('error') ?? ''), 200, ''),
                ]);
                $response->successful() ? $out['sent']++ : $out['refused']++;
            } else {
                $out['left']++;
            }
        }

        return $out;
    }

    /** The body Tantra's POST /api/ai/usage takes for one row. */
    public static function body(object $row): array
    {
        return array_filter([
            'task' => $row->task,
            'turn_id' => 'engine-'.$row->id,
            'provider' => self::provider((string) $row->provider),
            'model' => $row->model ?? 'unknown',
            'input_tokens' => $row->input_tokens === null ? null : (int) $row->input_tokens,
            'output_tokens' => $row->output_tokens === null ? null : (int) $row->output_tokens,
            'cache_read_input_tokens' => $row->cache_read_tokens === null ? null : (int) $row->cache_read_tokens,
            'cache_creation_input_tokens' => $row->cache_write_tokens === null ? null : (int) $row->cache_write_tokens,
            'outcome' => $row->outcome,
            'error' => $row->error,
        ], fn ($v) => $v !== null);
    }

    /** Delete rows sent more than 90 days ago. */
    public static function prune(): int
    {
        return DB::table(self::TABLE)->whereNotNull('reported_at')->where('created_at', '<', now()->subDays(90))->delete();
    }

    private static function send(object $row): Response
    {
        return Http::baseUrl(rtrim((string) config('tantra.crm.url'), '/'))
            ->withToken((string) config('tantra.crm.api_key'))
            ->acceptJson()
            ->timeout(10)
            ->post('/api/ai/usage', self::body($row));
    }

    /** Tantra's provider names: the connector provider, lower case, letters, digits, dashes and underscores. */
    private static function provider(string $provider): string
    {
        $p = Str::of($provider)->lower()->replaceMatches('/[^a-z0-9_-]/', '_')->limit(40, '')->toString();

        return strlen($p) >= 2 ? $p : 'server';
    }
}
