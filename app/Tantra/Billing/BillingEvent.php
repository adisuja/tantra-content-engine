<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** tantra: one Dodo webhook, kept once by its webhook-id (idempotency) with what it did. */
class BillingEvent extends Model
{
    use HasUuids;

    protected $table = 'tantra_billing_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
