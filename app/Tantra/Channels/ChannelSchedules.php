<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Actions\SocialAccount\UpdatePostingSchedule;
use App\Enums\SocialAccount\Status;
use App\Http\Requests\App\Channel\UpdatePostingScheduleRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Collection;

/**
 * tantra: each teammate's posting days and times, per channel (PRD 25.6).
 *
 * A channel's times are set by the review owner of its workspace and by the
 * Account owner, from the Team page, the Tantra API or MCP. Every write goes
 * through upstream's UpdatePostingSchedule, so the queue reflows the same way
 * it does on the channel settings page.
 *
 * Channels made outside the connect flow (Unipile, Zernio) start with default
 * times nobody chose. `meta.schedule_confirmed_at` is set the first time a
 * person saves a channel's times (here, on the channel settings page or in
 * the posting goal step after connecting), and until then the Team page asks
 * them to check the times.
 */
final class ChannelSchedules
{
    public const CONFIRMED = 'schedule_confirmed_at';

    /** A channel in the user's Account, or null. */
    public static function find(User $user, string $id): ?SocialAccount
    {
        return SocialAccount::query()
            ->whereIn('workspace_id', Workspace::query()->where('account_id', $user->account_id)->select('id'))
            ->find($id);
    }

    public static function canEdit(User $user, SocialAccount $channel): bool
    {
        if ($user->account?->owner_id === $user->id) {
            return true;
        }

        return WorkspaceSettings::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('review_owner_user_id', $user->id)
            ->exists();
    }

    /**
     * Upstream's validation for a schedule, built for input that does not come
     * through an HTTP request (MCP), so its same-day duplicate check sees it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rules(array $input): array
    {
        return (new UpdatePostingScheduleRequest($input))->rules();
    }

    /**
     * Saves through upstream's action (it reflows the queue under the channel
     * lock). A goal or schedule left out of the input stays as it is.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function update(SocialAccount $channel, array $validated): SocialAccount
    {
        $channel = app(UpdatePostingSchedule::class)->handle($channel, [
            'timezone' => $validated['timezone'],
            'posting_goal' => array_key_exists('posting_goal', $validated) ? $validated['posting_goal'] : $channel->posting_goal,
            'posting_schedule' => array_key_exists('posting_schedule', $validated) ? $validated['posting_schedule'] : $channel->posting_schedule?->toArray(),
        ]);

        self::confirm($channel);

        return $channel;
    }

    /** A person has saved this channel's times. */
    public static function confirm(SocialAccount $channel): void
    {
        $channel->refresh();
        $channel->forceFill(['meta' => [...($channel->meta ?? []), self::CONFIRMED => now()->toIso8601String()]])->saveQuietly();
    }

    public static function confirmed(SocialAccount $channel): bool
    {
        return filled(data_get($channel->meta, self::CONFIRMED));
    }

    /**
     * Every channel in the workspaces each person reviews, keyed by their user id.
     *
     * @param  list<string>  $userIds
     * @return Collection<string, Collection<int, SocialAccount>>
     */
    public static function reviewedBy(array $userIds): Collection
    {
        $owners = WorkspaceSettings::query()
            ->whereIn('review_owner_user_id', $userIds)
            ->pluck('review_owner_user_id', 'workspace_id');

        $channels = SocialAccount::query()
            ->with('workspace:id,name')
            ->whereIn('workspace_id', $owners->keys())
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        return collect($userIds)->mapWithKeys(fn (string $id): array => [
            $id => $channels->filter(fn (SocialAccount $c): bool => $owners->get($c->workspace_id) === $id)->values(),
        ]);
    }

    /**
     * Every connected channel they review has at least one enabled time, and
     * someone has saved (checked) those times.
     *
     * @param  Collection<int, SocialAccount>  $channels
     */
    public static function allSet(Collection $channels): bool
    {
        $connected = $channels->filter(fn (SocialAccount $c): bool => $c->status === Status::Connected);

        return $connected->isNotEmpty()
            && $connected->every(fn (SocialAccount $c): bool => ($c->posting_schedule?->slotCount() ?? 0) > 0 && self::confirmed($c));
    }

    /** @return array<string, mixed> */
    public static function card(SocialAccount $channel): array
    {
        return [
            'id' => $channel->id,
            'platform' => $channel->platform->value,
            'display_name' => $channel->display_name,
            'username' => $channel->username,
            'avatar_url' => $channel->avatar_url,
            'verified_badge' => $channel->verified_badge,
            'status' => $channel->status->value,
            'workspace' => $channel->workspace?->name,
            'confirmed' => self::confirmed($channel),
            'schedule' => self::state($channel),
        ];
    }

    /** @return array{timezone: string, posting_goal: int|null, posting_schedule: array<int, mixed>|null} */
    public static function state(SocialAccount $channel): array
    {
        return [
            'timezone' => $channel->timezone,
            'posting_goal' => $channel->posting_goal,
            'posting_schedule' => $channel->posting_schedule?->toArray(),
        ];
    }
}
