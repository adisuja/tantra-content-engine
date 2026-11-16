<?php

declare(strict_types=1);

namespace App\Tantra\Provision;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** tantra: PV-02. The Engine workspace that belongs to one Tantra team. */
class TantraWorkspaceLink extends Model
{
    protected $table = 'tantra_workspace_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['made_workspace' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(TantraWorkspacePerson::class, 'link_id');
    }
}
