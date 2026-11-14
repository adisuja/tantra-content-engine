<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use Carbon\CarbonImmutable;

/**
 * tantra: the weekly repurpose (PRD 25.10, review M6). For every original post
 * that went out, the cadence's next network is due once its week has come;
 * a network it already reached (by the cadence or a Repost) is skipped, and a
 * missed week catches up. Repurposed and reposted posts are never sources.
 * The first job of a run starts a cloud run; that run claims the rest.
 */
final class WeeklyCadence
{
    /** @return array{jobs: int, targets: int} */
    public static function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $jobs = $targets = 0;
        $fired = false;

        $accountIds = ReviewItem::query()->where('review_status', ReviewStatus::Published)->distinct()->pluck('account_id');
        foreach ($accountIds as $accountId) {
            $owner = User::query()->find(Account::query()->whereKey($accountId)->value('owner_id'));
            if ($owner === null) {
                continue;
            }
            $repurposed = IdeaMeta::query()->whereIn('kind', ['repurpose', 'repost'])->whereNotNull('review_item_id')->pluck('review_item_id');
            $items = ReviewItem::query()->where('account_id', $accountId)->where('review_status', ReviewStatus::Published)
                ->whereNotIn('id', $repurposed)->get();

            foreach ($items as $item) {
                $cadence = Cadence::for($accountId, $item->workspace_id);
                if (! $cadence->enabled) {
                    continue;
                }
                $published = $item->posts()->pluck('published_at')->filter()->min();
                if ($published === null) {
                    continue;
                }
                $published = CarbonImmutable::parse($published);
                $own = Reposts::NETWORK_OF[$item->platform] ?? $item->platform;
                $done = RepurposeProgress::query()->where('source_review_item_id', $item->id)->pluck('network')->all();

                $due = collect($cadence->sequence)
                    ->filter(fn (array $s): bool => $s['enabled'] && $s['week'] > 0 && $s['network'] !== $own
                        && ! in_array($s['network'], $done, true) && $published->addWeeks((int) $s['week'])->lte($now))
                    ->sortBy('week')->values();
                if ($due->isEmpty()) {
                    continue;
                }

                // One network per post per run: the next one due, so a backlog moves a week at a time.
                $step = $due->first();
                $channel = SocialAccount::query()->where('workspace_id', $item->workspace_id)->get()
                    ->first(fn (SocialAccount $c): bool => (Reposts::NETWORK_OF[$c->platform->value] ?? null) === $step['network']);
                $target = $channel ? ['channel_id' => $channel->id] : ['network' => $step['network']];

                Reposts::start($owner, $item, [$target], null, 'repurpose', (int) $step['week'], fire: ! $fired);
                $fired = true;
                $jobs++;
                $targets++;
            }
        }

        return ['jobs' => $jobs, 'targets' => $targets];
    }
}
