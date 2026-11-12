<?php

declare(strict_types=1);

namespace App\Tantra\Boost;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Zernio\ZernioClient;

/**
 * tantra: how each part of a post goes out, for the review screen and the boost
 * planner alike (PRD 10.5). The post and its first comment go through the
 * channel (TryPost for a personal LinkedIn, Zernio for the Tantra page, or by
 * hand for X and polls). Each team comment goes from that teammate's own
 * Unipile account; each reply from the author's own account, or, on a page
 * post, from the page through Zernio. Before go-live each entry says whether it
 * can be posted and why not; after, what actually happened.
 */
final class ThreadDelivery
{
    /**
     * profile key => user, profile, blocker and channel, through the workspace
     * mapped to that profile and its named review owner.
     *
     * @param  list<string>  $keys
     * @param  array<string, array<string, mixed>>  $cache  reused within one screen
     * @return array<string, array{user: ?User, profile: ?MemberProfile, blocker: ?string, via?: string}>
     */
    public static function people(string $accountId, array $keys, ?string $pageKey = null, array &$cache = []): array
    {
        $out = [];
        foreach ($keys as $key) {
            $memo = "{$accountId}|{$key}|".($pageKey === $key ? 'page' : '');
            if (isset($cache[$memo])) {
                $out[$key] = $cache[$memo];

                continue;
            }

            if ($pageKey !== null && $key === $pageKey) {
                $out[$key] = ['user' => null, 'profile' => null, 'via' => 'zernio', 'blocker' => ZernioClient::configured() ? null : 'Zernio is not set up'];
            } else {
                $settings = WorkspaceSettings::query()->where('brand_profile_key', $key)
                    ->whereHas('workspace', fn ($q) => $q->where('account_id', $accountId))->first();
                $user = $settings?->review_owner_user_id ? User::query()->find($settings->review_owner_user_id) : null;
                $profile = $user ? MemberProfile::query()->find($user->id) : null;

                $out[$key] = [
                    'user' => $user,
                    'profile' => $profile,
                    'via' => 'unipile',
                    'blocker' => match (true) {
                        $user === null => "no Engine login for {$key} yet",
                        $profile === null || $profile->unipile_account_id === null || $profile->unipile_confirmed_at === null => 'their LinkedIn is not matched for team comments yet (Team page)',
                        default => $profile->blocker(),
                    },
                ];
            }
            $cache[$memo] = $out[$key];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cache
     * @return array{post: array<string, mixed>, entries: list<array<string, mixed>>}
     */
    public static function for(ReviewItem $item, ?Post $post, ?SocialAccount $channel, array &$cache = []): array
    {
        $channel = $post?->socialAccount ?? $channel;
        $isPage = $channel?->platform === Platform::ZernioLinkedInPage;
        $postVia = match (true) {
            (bool) $item->assisted_manual => 'by_hand',
            $isPage => 'zernio',
            default => 'trypost',
        };
        $postState = match (true) {
            $postVia === 'by_hand' => ['ready', null],
            $channel === null => ['blocked', 'no LinkedIn is connected in this workspace yet'],
            $channel->status !== Status::Connected => ['blocked', 'the LinkedIn connection needs reconnecting'],
            default => ['ready', null],
        };

        $settings = WorkspaceSettings::query()->find($item->workspace_id);
        $thread = $item->thread();
        $keys = array_values(array_unique(array_map(fn (array $e): string => (string) ($e['by'] ?? ''), $thread)));
        $people = self::people((string) $item->account_id, $keys, $isPage ? $settings?->brand_profile_key : null, $cache);

        $run = $post ? BoostRun::query()->where('post_id', $post->id)->first() : null;
        $actions = $run ? BoostAction::query()->where('run_id', $run->id)->where('kind', '!=', 'reaction')->get()->keyBy('entry_index') : collect();

        $entries = [];
        $stateOf = [];
        foreach ($thread as $e) {
            $index = (int) $e['index'];
            $by = (string) ($e['by'] ?? '');
            $person = $people[$by] ?? ['user' => null, 'profile' => null, 'blocker' => 'unknown person', 'via' => 'unipile'];
            $via = $person['via'] ?? 'unipile';
            $parent = isset($e['reply_to']) ? (int) $e['reply_to'] : null;
            $action = $actions->get($index);

            [$state, $reason] = match (true) {
                $action !== null => [$action->status, $action->status === 'posted' ? null : $action->error],
                (bool) $e['excluded'] => ['removed', 'left out by the review owner'],
                ! Boost::enabled() => ['off', 'team comments are switched off'],
                $person['blocker'] !== null => ['blocked', $person['blocker']],
                $parent !== null && in_array($stateOf[$parent] ?? 'ready', ['blocked', 'removed', 'off'], true) => ['blocked', 'its comment cannot be posted'],
                default => ['ready', null],
            };
            $stateOf[$index] = $state;

            $entries[] = [
                'index' => $index,
                'kind' => $parent === null ? 'comment' : 'reply',
                'reply_to' => $parent,
                'by' => $by,
                'poster' => $via === 'zernio' ? ($channel?->display_name ?: 'the page') : ($person['user']?->name ?: $by),
                'via' => $via,
                'state' => $state,
                'reason' => $reason,
                'posted_at' => $action?->posted_at?->toIso8601ZuluString(),
            ];
        }

        return [
            'post' => [
                'via' => $postVia,
                'account' => $channel?->display_name,
                'state' => $postState[0],
                'reason' => $postState[1],
                'first_comment' => filled(data_get($item->payload, 'first_comment')),
            ],
            'entries' => $entries,
        ];
    }
}
