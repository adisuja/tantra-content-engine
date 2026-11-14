<?php

declare(strict_types=1);

namespace App\Tantra\Ideas;

use App\Models\Idea;
use App\Models\SocialAccount;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * tantra: what a Tantra idea adds to an upstream idea (PRD 25.8): the network
 * it is for, a phone preview, where it came from and the review item it became.
 *
 * @property string $idea_id
 * @property string $account_id
 * @property string $external_key
 * @property string|null $profile_key
 * @property string $platform
 * @property string|null $channel_id
 * @property string|null $format
 * @property string|null $template_key
 * @property string|null $hook
 * @property array<string, mixed>|null $preview
 * @property list<string>|null $upload_tokens
 * @property string $kind
 * @property string|null $source_review_item_id
 * @property string|null $source_post_id
 * @property int|null $cadence_week
 * @property Carbon|null $target_date
 * @property string|null $agent_job_id
 * @property string $state
 * @property string|null $review_item_id
 * @property-read Idea|null $idea
 */
class IdeaMeta extends Model
{
    public const KINDS = ['new', 'repurpose', 'repost'];

    public const STATE_IDEA = 'idea';

    public const STATE_IN_REVIEW = 'in_review';

    public const STATE_DROPPED = 'dropped';

    public const STATES = [self::STATE_IDEA, self::STATE_IN_REVIEW, self::STATE_DROPPED];

    protected $table = 'tantra_idea_meta';

    protected $primaryKey = 'idea_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'upload_tokens' => 'array',
            'cadence_week' => 'integer',
            'target_date' => 'date:Y-m-d',
        ];
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'channel_id');
    }

    public function reviewItem(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class, 'review_item_id');
    }
}
