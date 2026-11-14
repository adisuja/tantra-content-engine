<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tantra: one CRM short link the Engine made for a piece, a person, a platform and a placement.
 *
 * @property string $id
 * @property string|null $workspace_id
 * @property string $member_key
 * @property string $piece_ref
 * @property string|null $piece_name
 * @property string $platform
 * @property string $placement
 * @property string $medium
 * @property string $code
 * @property string $destination
 * @property string|null $short_url
 * @property string|null $long_url
 * @property bool $shortened
 * @property string|null $crm_link_id
 * @property string|null $crm_asset_id
 * @property string|null $crm_channel_id
 */
class TrackedLink extends Model
{
    use HasUuids;

    protected $table = 'tantra_tracked_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shortened' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** What goes into the copy: the short link, or the full UTM link for a LinkedIn preview card. */
    public function url(): string
    {
        return $this->shortened ? (string) $this->short_url : (string) $this->long_url;
    }
}
