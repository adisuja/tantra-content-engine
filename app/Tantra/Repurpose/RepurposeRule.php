<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** tantra: an Account's own rules for one network, added on top of the preset (PRD 25.10). */
final class RepurposeRule extends Model
{
    use HasUuids;

    protected $table = 'tantra_repurpose_rules';

    protected $fillable = ['account_id', 'network', 'rules', 'updated_by'];
}
