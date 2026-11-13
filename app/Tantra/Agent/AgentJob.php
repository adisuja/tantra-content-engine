<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * tantra: one piece of work for our Claude Code (PRD 25.5): build a template
 * from a post, repost to channels, repurpose for the cadence, write ideas,
 * rebrand a profile. The Engine applies the result; the agent never writes to
 * the Engine directly.
 *
 * @property string $id
 * @property string $kind
 * @property string $status
 * @property string $account_id
 * @property string|null $workspace_id
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $result
 * @property list<array{at: string, line: string}>|null $log
 * @property int $attempts
 * @property int $dispatches
 */
final class AgentJob extends Model
{
    use HasUuids;

    /** Started by someone who is not an admin: waits for an admin to press Run (PRD 25.2.5, review B1). */
    public const AWAITING_ADMIN = 'awaiting_admin';

    public const QUEUED = 'queued';

    public const DISPATCHED = 'dispatched';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::QUEUED, self::DISPATCHED, self::RUNNING];

    public const KINDS = ['ping', 'template_from_post', 'repost', 'repurpose', 'ideas_batch', 'rebrand', 'custom'];

    protected $table = 'tantra_agent_jobs';

    protected $fillable = [
        'kind', 'status', 'account_id', 'workspace_id', 'requested_by', 'subject_type', 'subject_id', 'input', 'result',
        'summary', 'error', 'log', 'runner_id', 'claim_token', 'claude_account_id', 'session_url', 'attempts', 'dispatches',
        'dispatched_at', 'started_at', 'heartbeat_at', 'finished_at', 'purge_after',
    ];

    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'input' => 'array', 'result' => 'array', 'log' => 'array', 'attempts' => 'integer', 'dispatches' => 'integer',
            'dispatched_at' => 'datetime', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime', 'purge_after' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AgentRunner, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(AgentRunner::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [...self::OPEN, self::AWAITING_ADMIN], true);
    }

    public function appendLog(string $line): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'line' => mb_substr($line, 0, 2000)];
        $this->log = array_slice($log, -300);
    }

    /** What anyone but an admin may see: the outcome only (PRD 25.4). */
    public function toOutcome(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind,
            'status' => match (true) {
                $this->status === self::AWAITING_ADMIN => 'waiting',
                $this->isOpen() => 'working',
                default => $this->status,
            },
            'summary' => $this->status === self::DONE ? $this->summary : null,
        ];
    }

    /** @return array<string, mixed> the full record, for the ops screen and the ops API */
    public function toOps(bool $withDetail = false): array
    {
        $out = [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status, 'summary' => $this->summary,
            'error' => $this->error, 'workspace' => $this->workspace?->name, 'requested_by' => $this->requester?->name,
            'runner' => $this->runner?->name, 'session_url' => $this->session_url, 'attempts' => $this->attempts,
            'dispatches' => $this->dispatches, 'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(), 'finished_at' => $this->finished_at?->toIso8601String(),
        ];

        return $withDetail ? [...$out, 'input' => $this->input, 'result' => $this->result, 'log' => $this->log ?? []] : $out;
    }
}
