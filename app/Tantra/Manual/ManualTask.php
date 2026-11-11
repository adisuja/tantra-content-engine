<?php

declare(strict_types=1);

namespace App\Tantra\Manual;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\ReviewItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * tantra: a post the owner publishes by hand (PRD 12): an X post or thread, or a
 * poll on a personal LinkedIn profile. waiting (no time yet) > due > posted,
 * skipped or expired (48 hours after it fell due).
 */
class ManualTask extends Model
{
    use HasUuids;

    public const EXPIRES_AFTER_HOURS = 48;

    protected $table = 'tantra_manual_tasks';

    protected $fillable = [
        'review_item_id', 'workspace_id', 'social_account_id', 'owner_user_id', 'kind', 'status', 'due_at', 'slot_fallback', 'text', 'thread', 'poll',
        'media_url', 'posted_url', 'posted_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'slot_fallback' => 'boolean',
            'posted_at' => 'datetime',
            'notified_at' => 'datetime',
            'thread' => 'array',
            'poll' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class, 'review_item_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['waiting', 'due'], true);
    }

    /**
     * The slot instants that open manual tasks hold, as UTC timestamps keyed by
     * channel, for the slot logic next to Post::scopeOccupyingSlotsOn().
     *
     * @param  list<string>  $channelIds
     * @return array<string, array<int, true>>
     */
    public static function heldSlots(array $channelIds, CarbonInterface $after, ?CarbonInterface $until = null, ?string $exceptId = null): array
    {
        if ($channelIds === []) {
            return [];
        }

        $held = [];
        self::query()
            ->whereIn('social_account_id', $channelIds)
            ->whereIn('status', ['waiting', 'due'])
            ->where('due_at', '>', $after)
            ->when($until, fn ($q) => $q->where('due_at', '<', $until))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['social_account_id', 'due_at'])
            ->each(function (self $task) use (&$held): void {
                $held[$task->social_account_id][$task->due_at->getTimestamp()] = true;
            });

        return $held;
    }

    /**
     * The same slots as a query of (social_account_id, scheduled_at) rows, for a
     * UNION with TryPost's own taken-slots query (one query, however many channels).
     *
     * @param  list<string>  $channelIds
     */
    public static function heldSlotsQuery(array $channelIds, CarbonInterface $from, CarbonInterface $until): QueryBuilder
    {
        return DB::table('tantra_manual_tasks')
            ->select(['social_account_id', 'due_at as scheduled_at'])
            ->whereIn('social_account_id', $channelIds)
            ->whereIn('status', ['waiting', 'due'])
            ->where('due_at', '>=', $from)
            ->where('due_at', '<', $until);
    }

    /** @return array<int, true> */
    public static function heldSlotsOn(string $channelId, CarbonInterface $after, ?string $exceptId = null): array
    {
        return self::heldSlots([$channelId], $after, null, $exceptId)[$channelId] ?? [];
    }
}
