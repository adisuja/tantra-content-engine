<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** tantra: one original post on one network, so the cadence and Repost never do it twice (review M6). */
final class RepurposeProgress extends Model
{
    use HasUuids;

    protected $table = 'tantra_repurpose_progress';

    protected $fillable = ['account_id', 'source_review_item_id', 'network', 'status', 'via', 'agent_job_id', 'idea_id', 'review_item_id'];
}
