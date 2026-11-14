<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * tantra: the repurposing cadence (PRD 25.10): which network a post moves to
 * in which week after it first went out. One per Account, optionally one per
 * workspace that overrides it.
 *
 * @property list<array{network: string, week: int, enabled: bool}> $sequence
 */
final class Cadence extends Model
{
    use HasUuids;

    public const NETWORKS = ['linkedin', 'facebook', 'instagram', 'youtube', 'x', 'threads', 'tiktok', 'bluesky', 'pinterest'];

    /** Week 0 is where the post first went out. */
    public const DEFAULT = [
        ['network' => 'linkedin', 'week' => 0, 'enabled' => true],
        ['network' => 'facebook', 'week' => 1, 'enabled' => true],
        ['network' => 'instagram', 'week' => 2, 'enabled' => true],
        ['network' => 'youtube', 'week' => 3, 'enabled' => true],
        ['network' => 'x', 'week' => 4, 'enabled' => true],
        ['network' => 'threads', 'week' => 5, 'enabled' => true],
        ['network' => 'tiktok', 'week' => 6, 'enabled' => true],
    ];

    protected $table = 'tantra_repurpose_cadences';

    protected $fillable = ['account_id', 'workspace_id', 'sequence', 'enabled'];

    protected function casts(): array
    {
        return ['sequence' => 'array', 'enabled' => 'boolean'];
    }

    public static function for(string $accountId, ?string $workspaceId = null): self
    {
        return ($workspaceId ? self::query()->where('account_id', $accountId)->where('workspace_id', $workspaceId)->first() : null)
            ?? self::query()->where('account_id', $accountId)->whereNull('workspace_id')->first()
            ?? new self(['account_id' => $accountId, 'workspace_id' => null, 'sequence' => self::DEFAULT, 'enabled' => true]);
    }
}
