<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** tantra: a channel's day: follower gain and page views (the Tantra page through Zernio). */
class AccountSnapshot extends Model
{
    use HasUuids;

    protected $table = 'tantra_account_snapshots';

    protected $fillable = ['social_account_id', 'date', 'followers', 'follower_gain', 'page_views', 'raw'];

    protected function casts(): array
    {
        return ['date' => 'date', 'raw' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
