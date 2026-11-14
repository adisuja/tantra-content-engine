<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tantra: one workspace's Content OS plan. Statuses: none (never paid), active,
 * on_hold (a charge failed; grace period runs), cancelled (runs to the end of
 * the paid period), expired, failed (first payment never went through) and
 * comped (free, set by hand).
 *
 * @property string $status
 */
class WorkspacePlan extends Model
{
    use HasUuids;

    protected $table = 'tantra_workspace_plans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'next_billing_at' => 'datetime', 'period_ends_at' => 'datetime', 'payment_failed_at' => 'datetime',
            'grace_ends_at' => 'datetime', 'renewal_notice_for' => 'datetime', 'paused_notice_at' => 'datetime',
            'winback_sent_at' => 'datetime', 'activated_at' => 'datetime', 'dunning_step' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function for(string $workspaceId): self
    {
        return self::query()->firstOrCreate(['workspace_id' => $workspaceId]);
    }

    /** Whether the workspace may publish: paid up, in its grace period, cancelled but still in the paid period, or comped. */
    public function allowsPublishing(): bool
    {
        return match ($this->status) {
            'active', 'comped' => true,
            'on_hold' => $this->grace_ends_at !== null && $this->grace_ends_at->isFuture(),
            'cancelled' => $this->period_ends_at !== null && $this->period_ends_at->isFuture(),
            default => false,
        };
    }
}
