<?php

declare(strict_types=1);

namespace App\Actions\Post\Queue;

use App\Models\Post;
use Carbon\CarbonInterface;

class ListTakenSlots
{
    /**
     * The instants already held on each channel (Post::scopeOccupyingSlotsOn()),
     * as UTC strings keyed by channel id, in one query for every channel,
     * from `$from` and before `$until`.
     *
     * @param  list<string>  $channelIds
     * @return array<string, list<string>>
     */
    public static function handle(array $channelIds, CarbonInterface $from, CarbonInterface $until): array
    {
        $taken = Post::query()
            ->whereIn('social_account_id', $channelIds)
            ->holdingSlot()
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<', $until)
            ->get(['social_account_id', 'scheduled_at'])
            ->groupBy('social_account_id');
        $held = \App\Tantra\Manual\ManualTask::heldSlots($channelIds, $from->toImmutable()->subSecond(), $until); // tantra: polls posted by hand hold their slot

        return collect($channelIds)
            ->mapWithKeys(fn (string $channelId): array => [$channelId => $taken->get($channelId, collect())
                ->sortBy(fn (Post $post): int => $post->scheduled_at->getTimestamp())
                ->map(fn (Post $post): string => $post->scheduled_at->toIso8601ZuluString())
                ->concat(collect(array_keys($held[$channelId] ?? []))->map(fn (int $ts): string => \Carbon\CarbonImmutable::createFromTimestampUTC($ts)->toIso8601ZuluString())) // tantra:
                ->sort()
                ->unique()
                ->values()
                ->all()])
            ->all();
    }
}
