<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** tantra: one reaction, comment or reply a boost run posts (PRD 10.5 steps 4 and 5). */
class BoostAction extends Model
{
    use HasUuids;

    protected $table = 'tantra_boost_actions';

    protected $fillable = [
        'run_id', 'entry_index', 'parent_action_id', 'user_id', 'profile_key', 'unipile_account_id', 'via', 'kind', 'reaction_type',
        'text', 'offset_seconds', 'run_at', 'status', 'unipile_ref', 'error', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['run_at' => 'datetime', 'posted_at' => 'datetime', 'offset_seconds' => 'integer', 'entry_index' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BoostRun::class, 'run_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_action_id');
    }
}
