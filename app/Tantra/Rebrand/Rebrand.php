<?php

declare(strict_types=1);

namespace App\Tantra\Rebrand;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * tantra: a rebrand of one person's LinkedIn profile (PRD 25.11).
 *
 * @property string $id
 * @property string $user_id
 * @property string $status
 * @property array<string, mixed>|null $proposal
 * @property array<string, mixed>|null $applied
 */
final class Rebrand extends Model
{
    use HasUuids;

    protected $table = 'tantra_rebrands';

    protected $fillable = ['account_id', 'user_id', 'requested_by', 'agent_job_id', 'status', 'proposal', 'applied', 'banner_path', 'applied_at'];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'applied' => 'array', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id, 'status' => $this->status, 'proposal' => $this->proposal, 'applied' => $this->applied,
            'banner' => $this->banner_path ? Storage::url($this->banner_path) : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
