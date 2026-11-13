<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * tantra: a Claude account that works agent jobs (PRD 25.4). A cloud task is
 * fired over its API trigger; a local CLI account is a config folder on a
 * runner's machine. Secrets are encrypted and never sent to the browser.
 *
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property string|null $fire_url
 * @property string|null $secret
 * @property string|null $config_dir
 * @property bool $enabled
 */
final class ClaudeAccount extends Model
{
    use HasUuids;

    public const KINDS = ['cloud_task', 'local_cli', 'api_key'];

    protected $table = 'tantra_claude_accounts';

    protected $fillable = ['name', 'kind', 'fire_url', 'secret', 'config_dir', 'owner_user_id', 'enabled', 'last_test_at', 'last_test_result'];

    protected $hidden = ['fire_url', 'secret'];

    protected function casts(): array
    {
        return [
            'fire_url' => 'encrypted',
            'secret' => 'encrypted',
            'enabled' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> what the ops screen may show */
    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'config_dir' => $this->config_dir,
            'enabled' => $this->enabled,
            'has_fire_url' => filled($this->fire_url),
            'has_secret' => filled($this->secret),
            'last_test_at' => $this->last_test_at?->toIso8601String(),
            'last_test_result' => $this->last_test_result,
        ];
    }
}
