<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * tantra: something that works agent jobs with a runner token: the cloud task
 * or the local runner on a laptop (PRD 25.5). The token is shown once and
 * stored as a hash; it can only read, log and finish jobs.
 *
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property list<string>|null $kinds
 */
final class AgentRunner extends Model
{
    use HasUuids;

    protected $table = 'tantra_agent_runners';

    protected $fillable = ['name', 'kind', 'claude_account_id', 'token_hash', 'kinds', 'last_seen_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['kinds' => 'array', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<ClaudeAccount, $this> */
    public function claudeAccount(): BelongsTo
    {
        return $this->belongsTo(ClaudeAccount::class);
    }

    /** @return array{0: self, 1: string} the runner and its token (shown once) */
    public static function issue(string $name, string $kind, ?string $claudeAccountId = null, ?array $kinds = null): array
    {
        $token = 'tar_'.Str::random(48);
        $runner = self::query()->create([
            'name' => $name, 'kind' => $kind, 'claude_account_id' => $claudeAccountId,
            'token_hash' => hash('sha256', $token), 'kinds' => $kinds,
        ]);

        return [$runner, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    /** @return array<string, mixed> */
    public function toPublic(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'kind' => $this->kind, 'kinds' => $this->kinds,
            'claude_account' => $this->claudeAccount?->name, 'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked' => $this->revoked_at !== null,
        ];
    }
}
