<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** tantra: how a post is rewritten for one network (PRD 25.10), from tantra-content knowledge/content/repurpose. */
final class RepurposePreset extends Model
{
    use HasUuids;

    protected $table = 'tantra_repurpose_presets';

    protected $fillable = ['network', 'source', 'title', 'rules', 'version', 'updated_by'];
}
