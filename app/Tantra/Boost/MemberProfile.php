<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tantra: a teammate's boost settings (PRD 9): their confirmed Unipile account
 * and their standing consent to post approved team comments in their name.
 */
class MemberProfile extends Model
{
    protected $table = 'tantra_member_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'unipile_account_id', 'unipile_name', 'unipile_match_method', 'unipile_confirmed_by', 'unipile_confirmed_at',
        'boost_consent_at', 'expertise', 'daily_boost_cap', 'boost_paused_until',
    ];

    protected function casts(): array
    {
        return [
            'unipile_confirmed_at' => 'datetime',
            'boost_consent_at' => 'datetime',
            'boost_paused_until' => 'datetime',
            'daily_boost_cap' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Why this person cannot post a boost entry right now, or null when they can. */
    public function blocker(): ?string
    {
        return match (true) {
            $this->unipile_account_id === null || $this->unipile_confirmed_at === null => 'no confirmed Unipile account',
            $this->boost_consent_at === null => 'no consent to post in their name',
            $this->boost_paused_until !== null && $this->boost_paused_until->isFuture() => 'paused after a LinkedIn restriction',
            default => null,
        };
    }
}
