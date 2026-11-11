<?php

declare(strict_types=1);

namespace App\Tantra\Review\Models;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $workspace_id
 * @property string|null $review_owner_user_id
 * @property string|null $brand_profile_key
 * @property string|null $default_time
 * @property string|null $timezone
 * @property bool $boost_enabled
 */
class WorkspaceSettings extends Model
{
    protected $table = 'tantra_workspace_settings';

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['workspace_id', 'review_owner_user_id', 'brand_profile_key', 'default_time', 'timezone', 'boost_enabled'];

    protected function casts(): array
    {
        return ['boost_enabled' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function reviewOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_owner_user_id');
    }
}
