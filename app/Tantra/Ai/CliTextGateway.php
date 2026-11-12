<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use RuntimeException;

use function Laravel\Ai\ulid;

/**
 * tantra: runs a prompt through the official Claude Code CLI (`claude -p`, on
 * the token from `claude setup-token`) or the official Codex CLI (`codex exec`,
 * on the auth.json from `codex login`). Each run gets its own throwaway home
 * directory so nothing leaks between Accounts; Codex's refreshed sign-in is
 * written back to the connector. Every tool is off and the child gets a
 * minimal environment, so the CLI only writes text and never sees server secrets.
 */
final class CliTextGateway implements StepTextGateway
{
    public const CLAUDE = 'claude';

    public const CODEX = 'codex';

    public function __construct(private readonly string $cli, private readonly string $secret, private readonly ?string $connectorId = null) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $text = $this->run($model, self::buildPrompt($instructions, $messages, $schema), $timeout ?? 180);

        $structured = null;
        if ($schema !== null) {
            $structured = self::extractJson($text) ?? throw new RuntimeException('The CLI answer was not the JSON the feature needs: '.Str::limit($text, 200));
        }

        return new StepResponse($text, [], FinishReason::Stop, new Usage, new Meta($provider->name(), $model), $structured);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $step = $this->generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
        $messageId = ulid();

        yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);
        yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);
        yield (new TextDelta(ulid(), $messageId, $step->text, time()))->withInvocationId($invocationId);
        yield (new TextEnd(ulid(), $messageId, time()))->withInvocationId($invocationId);

        return $step;
    }

    /**
     * @param  Message[]  $messages
     * @param  array<string, mixed>|null  $schema
     */
    public static function buildPrompt(?string $instructions, array $messages, ?array $schema): string
    {
        $parts = [];
        if (filled($instructions)) {
            $parts[] = trim((string) $instructions);
        }
        foreach ($messages as $message) {
            $parts[] = ($message->role->value === 'user' ? '' : strtoupper($message->role->value).': ').trim((string) $message->content);
        }
        if ($schema !== null) {
            $parts[] = "Answer with one JSON object and nothing else, no code fences, matching this JSON schema:\n"
                .json_encode((new ObjectSchema($schema))->toSchema(), JSON_UNESCAPED_SLASHES);
        }

        return implode("\n\n", $parts);
    }

    /** @return array<string, mixed>|null */
    public static function extractJson(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)) ?? $text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function run(string $model, string $prompt, int $timeout): string
    {
        // Outside the app folder, so the CLIs do not pick up the repo's own agent files.
        $home = sys_get_temp_dir().'/tantra-cli/'.Str::uuid();
        File::ensureDirectoryExists($home, 0700);

        try {
            return $this->cli === self::CLAUDE
                ? $this->runClaude($home, $model, $prompt, $timeout)
                : $this->runCodex($home, $model, $prompt, $timeout);
        } finally {
            File::deleteDirectory($home);
        }
    }

    private function runClaude(string $home, string $model, string $prompt, int $timeout): string
    {
        $binary = (string) config('tantra.cli.claude', 'claude');
        $result = Process::path($home)->timeout($timeout)
            ->env(self::minimalEnv($home, [
                'CLAUDE_CODE_OAUTH_TOKEN' => trim($this->secret),
                'DISABLE_TELEMETRY' => '1',
                'DISABLE_AUTOUPDATER' => '1',
                'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
            ]))
            ->input($prompt)
            // --tools "" turns every built-in tool off: no shell, no file reads.
            ->run([$binary, '-p', '--output-format', 'json', '--model', $model, '--max-turns', '1', '--tools', '']);

        $out = json_decode($result->output(), true);
        if (! is_array($out)) {
            throw new RuntimeException(self::cliError('Claude Code', $result->errorOutput() ?: $result->output(), $result->exitCode()));
        }
        if (($out['is_error'] ?? false) === true || ($out['subtype'] ?? 'success') !== 'success') {
            throw new RuntimeException('Claude Code: '.Str::limit((string) ($out['result'] ?? $out['subtype'] ?? 'error'), 300));
        }

        return (string) ($out['result'] ?? '');
    }

    private function runCodex(string $home, string $model, string $prompt, int $timeout): string
    {
        $binary = (string) config('tantra.cli.codex', 'codex');
        $codexHome = $home.'/.codex';
        File::ensureDirectoryExists($codexHome, 0700);
        File::put($codexHome.'/auth.json', trim($this->secret));
        chmod($codexHome.'/auth.json', 0600);
        $answerFile = $home.'/answer.txt';

        $result = Process::path($home)->timeout($timeout)
            ->env(self::minimalEnv($home, ['CODEX_HOME' => $codexHome, 'RUST_LOG' => 'error']))
            ->input($prompt)
            // No shell, exec, apps, browser or web tools: Codex only writes text.
            ->run([
                $binary, 'exec', '--skip-git-repo-check', '--ephemeral', '--ignore-user-config', '--ignore-rules',
                '--sandbox', 'read-only',
                '--disable', 'shell_tool', '--disable', 'unified_exec', '--disable', 'apps',
                '--disable', 'browser_use', '--disable', 'in_app_browser', '--disable', 'view_image',
                '--disable', 'multi_agent', '--disable', 'goals', '--disable', 'plugins', '--disable', 'remote_plugin',
                '--disable', 'computer_use', '--disable', 'code_mode_host', '--disable', 'image_generation',
                '-c', 'web_search="disabled"',
                '--model', $model, '--output-last-message', $answerFile, '-',
            ]);

        // Codex refreshes its tokens in auth.json; keep the newest sign-in,
        // unless someone pasted a new one while this run was going.
        $auth = File::exists($codexHome.'/auth.json') ? trim(File::get($codexHome.'/auth.json')) : '';
        if ($this->connectorId !== null && $auth !== '' && $auth !== trim($this->secret) && json_decode($auth) !== null) {
            DB::transaction(function () use ($auth): void {
                $connector = AiConnector::query()->whereKey($this->connectorId)->lockForUpdate()->first();
                if ($connector !== null && trim((string) $connector->secret) === trim($this->secret)) {
                    $connector->forceFill(['secret' => $auth])->saveQuietly();
                }
            });
        }

        if (! $result->successful() || ! File::exists($answerFile)) {
            $lines = array_filter(array_map(trim(...), explode("\n", $result->errorOutput() ?: $result->output())));
            throw new RuntimeException(self::cliError('Codex', implode(' ', array_slice($lines, -4)), $result->exitCode()));
        }

        return trim(File::get($answerFile));
    }

    /**
     * Only what the CLI needs. The PHP process carries the app key and the
     * database password; a CLI child never sees them.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string|false>
     */
    public static function minimalEnv(string $home, array $extra): array
    {
        $env = [];
        foreach (array_keys(getenv()) as $name) {
            $env[$name] = false;
        }
        foreach (array_keys($_ENV) + array_keys($_SERVER) as $name) {
            if (is_string($name) && preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                $env[$name] = false;
            }
        }

        return array_merge($env, [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => $home,
            'TMPDIR' => $home,
            'LANG' => 'C.UTF-8',
        ], $extra);
    }

    private static function cliError(string $name, string $output, ?int $exitCode): string
    {
        if ($exitCode === 127 || str_contains($output, 'command not found') || str_contains($output, 'No such file or directory')) {
            return "{$name} CLI is not installed on the server.";
        }

        return "{$name} (exit {$exitCode}): ".Str::limit(trim(preg_replace('/\s+/', ' ', $output) ?? ''), 300);
    }
}
