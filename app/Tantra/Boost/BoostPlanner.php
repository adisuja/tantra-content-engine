<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Closure;

/**
 * tantra: turns a post's approved team thread into timed actions (PRD 10.5).
 *
 * - Each top-level comment is posted by the person it was written for, at a
 *   random moment 1 to 3 minutes after go-live, at least 10 seconds apart.
 * - Each person who comments also reacts, 5 to 20 seconds before their first comment.
 * - Each reply lands in its parent's thread 2 to 8 minutes after the parent,
 *   at least 30 seconds after the previous reply on the post.
 * - An entry whose person has no confirmed Unipile account or no consent is
 *   skipped with the reason, and so are the replies under it.
 * - On a Tantra page post the author is the page: its entries go through Zernio.
 */
final class BoostPlanner
{
    /** @param (Closure(int, int): int)|null $random */
    public function __construct(private ?Closure $random = null)
    {
        $this->random ??= fn (int $min, int $max): int => random_int($min, $max);
    }

    /** @return list<BoostAction> the actions created (queued and skipped) */
    public function plan(BoostRun $run, CarbonImmutable $start): array
    {
        $item = $run->item;
        // The thread as approved: the review owner's edits applied, removed entries left out.
        $thread = [];
        foreach ($item->thread() as $entry) {
            if (! $entry['excluded']) {
                $thread[$entry['index']] = $entry;
            }
        }
        // On a Tantra page post the page itself answers, through Zernio (Spike S6).
        $pageKey = $run->post?->platform === Platform::ZernioLinkedInPage
            ? WorkspaceSettings::query()->find($item->workspace_id)?->brand_profile_key
            : null;
        $people = $this->people($item->account_id, array_unique(array_map(fn (array $c): string => (string) ($c['by'] ?? ''), $thread)), $pageKey);

        $config = config('tantra.boost');
        $window = ($this->random)((int) $config['window_min'], (int) $config['window_max']);
        $run->forceFill(['window_start_at' => $start, 'window_seconds' => $window])->save();

        $actions = [];
        $byIndex = [];
        $tops = array_filter($thread, fn (array $c): bool => ($c['reply_to'] ?? null) === null);

        // Top-level comments: random offsets inside the window, at least 10 s apart.
        $offsets = $this->spread(count($tops), (int) $config['window_min'], $window, 10);
        $reacted = [];
        $k = 0;
        foreach ($tops as $index => $entry) {
            $person = $people[$entry['by'] ?? ''] ?? null;
            $offset = $offsets[$k++];
            $blocker = $person['blocker'];

            if ($blocker === null && ($person['via'] ?? 'unipile') === 'unipile' && ! isset($reacted[$entry['by']])) {
                $reacted[$entry['by']] = true;
                $actions[] = $this->action($run, $start, null, $index, $person, $entry['by'], 'reaction', null, max(0, $offset - ($this->random)(5, 20)), $this->reaction());
            }

            $byIndex[$index] = $actions[] = $this->action($run, $start, null, $index, $person, $entry['by'], 'comment', $entry['text'], $offset, null, $blocker);
        }

        // Replies, in the order written: after their parent, 30 s or more apart.
        $last = 0;
        foreach ($thread as $index => $entry) {
            $parentIndex = $entry['reply_to'] ?? null;
            if ($parentIndex === null) {
                continue;
            }

            $parent = $byIndex[$parentIndex] ?? null;
            $person = $people[$entry['by'] ?? ''] ?? null;
            $blocker = $parent === null ? 'its comment is not in the thread'
                : ($parent->status === 'skipped' ? 'its comment was skipped' : $person['blocker']);

            $offset = ($parent?->offset_seconds ?? 0) + ($this->random)((int) $config['reply_min'], (int) $config['reply_max']);
            $offset = max($offset, $last + 30);
            if ($blocker === null) {
                $last = $offset;
            }

            $byIndex[$index] = $actions[] = $this->action($run, $start, $parent, $index, $person, $entry['by'], 'reply', $entry['text'], $offset, null, $blocker);
        }

        return $actions;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{user: ?User, profile: ?MemberProfile, blocker: ?string, via?: string}>
     */
    private function people(string $accountId, array $keys, ?string $pageKey = null): array
    {
        // The same rules the review screen shows (ThreadDelivery).
        return ThreadDelivery::people($accountId, $keys, $pageKey);
    }

    /**
     * @param  array{user: ?User, profile: ?MemberProfile, blocker: ?string, via?: string}|null  $person
     */
    private function action(BoostRun $run, CarbonImmutable $start, ?BoostAction $parent, int $index, ?array $person, string $key, string $kind, ?string $text, int $offset, ?string $reaction, ?string $blocker = null): BoostAction
    {
        return BoostAction::query()->create([
            'run_id' => $run->id,
            'entry_index' => $index,
            'parent_action_id' => $parent?->id,
            'user_id' => $person['user']?->id,
            'profile_key' => $key,
            'unipile_account_id' => $person['profile']?->unipile_account_id,
            'via' => $person['via'] ?? 'unipile',
            'kind' => $kind,
            'reaction_type' => $reaction,
            'text' => $text,
            'offset_seconds' => $offset,
            'run_at' => $start->addSeconds($offset),
            'status' => $blocker === null ? 'queued' : 'skipped',
            'error' => $blocker,
        ]);
    }

    /** @return list<int> $n increasing offsets in [$min, $max], at least $gap apart */
    private function spread(int $n, int $min, int $max, int $gap): array
    {
        if ($n === 0) {
            return [];
        }

        $max = max($max, $min + $gap * ($n - 1));
        $room = $max - $min - $gap * ($n - 1);
        $points = [];
        for ($i = 0; $i < $n; $i++) {
            $points[] = ($this->random)(0, max(0, $room));
        }
        sort($points);

        return array_map(fn (int $p, int $i): int => $min + $p + $gap * $i, $points, array_keys($points));
    }

    private function reaction(): string
    {
        // Weighted toward like and insightful (PRD 10.5 step 4).
        $roll = ($this->random)(1, 10);

        return match (true) {
            $roll <= 5 => 'linkedin_like',
            $roll <= 8 => 'linkedin_insightful',
            $roll === 9 => 'linkedin_support',
            default => 'linkedin_celebrate',
        };
    }
}
