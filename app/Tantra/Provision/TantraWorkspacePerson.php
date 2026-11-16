<?php

declare(strict_types=1);

namespace App\Tantra\Provision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** tantra: PV-02. A person provisioning added to a linked workspace (a removal touches only these). */
class TantraWorkspacePerson extends Model
{
    protected $table = 'tantra_workspace_people';

    protected $guarded = [];

    public function link(): BelongsTo
    {
        return $this->belongsTo(TantraWorkspaceLink::class, 'link_id');
    }
}
