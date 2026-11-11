<?php

declare(strict_types=1);

namespace App\Tantra\Giveaway;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** tantra: one answered comment on a giveaway post, and its place on the "DMs to send" list (PRD 10.5 step 6). */
class GiveawayReply extends Model
{
    use HasUuids;

    protected $table = 'tantra_giveaway_replies';

    protected $fillable = [
        'post_id', 'review_item_id', 'comment_id', 'commenter_name', 'commenter_url', 'comment_text', 'reply_text', 'reply_id',
        'status', 'error', 'replied_at', 'dm_sent_at',
    ];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime', 'dm_sent_at' => 'datetime'];
    }
}
