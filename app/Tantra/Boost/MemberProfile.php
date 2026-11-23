<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
        'boost_consent_at', 'boost_opted_out_at', 'expertise', 'daily_boost_cap', 'boost_paused_until', 'boost_member_paused_until',
        // Read from LinkedIn through Unipile (App\Tantra\LinkedIn\LinkedInSync), never typed in.
        'unipile_status', 'linkedin_member_id', 'linkedin_public_identifier', 'linkedin_name', 'linkedin_headline',
        'linkedin_profile_url', 'linkedin_followers', 'linkedin_picture_path', 'linkedin_picture_source', 'linkedin_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'unipile_confirmed_at' => 'datetime',
            'boost_consent_at' => 'datetime',
            'boost_opted_out_at' => 'datetime',
            'boost_paused_until' => 'datetime',
            'boost_member_paused_until' => 'datetime',
            'daily_boost_cap' => 'integer',
            'linkedin_followers' => 'integer',
            'linkedin_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Their LinkedIn picture, as stored by the sync (a public URL), or null. */
    public function linkedinPictureUrl(): ?string
    {
        return $this->linkedin_picture_path ? Storage::url($this->linkedin_picture_path) : null;
    }

    /** Why this person cannot post a boost entry right now, or null when they can. */
    public function blocker(): ?string
    {
        return match (self::status()) {
            self::NO_LINKEDIN => 'no confirmed Unipile account',
            self::OPTED_OUT => 'turned team boosts off',
            self::NO_CONSENT => 'no consent to post in their name',
            self::PAUSED_RESTRICTION => 'paused after a LinkedIn restriction',
            self::PAUSED_MEMBER => 'paused by the member',
            default => null,
        };
    }

    public const string READY = 'ready';

    public const string NO_LINKEDIN = 'no_linkedin';

    public const string OPTED_OUT = 'opted_out';

    public const string NO_CONSENT = 'no_consent';

    public const string PAUSED_RESTRICTION = 'paused_restriction';

    public const string PAUSED_MEMBER = 'paused_member';

    /** One word for Brand > Team (One Content app PRD 3.5): ready, no LinkedIn, opted out, no consent, or which pause. */
    public function status(): string
    {
        return match (true) {
            $this->unipile_account_id === null || $this->unipile_confirmed_at === null => self::NO_LINKEDIN,
            $this->boost_opted_out_at !== null => self::OPTED_OUT,
            $this->boost_consent_at === null => self::NO_CONSENT,
            $this->boost_paused_until !== null && $this->boost_paused_until->isFuture() => self::PAUSED_RESTRICTION,
            $this->boost_member_paused_until !== null && $this->boost_member_paused_until->isFuture() => self::PAUSED_MEMBER,
            default => self::READY,
        };
    }
}
