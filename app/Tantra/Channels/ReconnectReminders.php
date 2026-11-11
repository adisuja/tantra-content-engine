<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * tantra: keeps every reviewed workspace's LinkedIn channel connected (PRD M3).
 *
 * LinkedIn gives a personal connection 60 days and no refresh. Once a day the
 * review owner of each workspace hears about a channel 7 days and 1 day before
 * it runs out, and the day it drops. Upstream already tells the workspace
 * owner about a drop; this reaches the teammate who reviews there.
 */
final class ReconnectReminders
{
    /** @var list<int> days before expiry that get a reminder */
    public const WARN_DAYS = [7, 1];

    /** Channels a user should reconnect, for the review inbox. @return list<array<string, mixed>> */
    public static function forUser(User $user): array
    {
        return self::channels()
            ->filter(fn (SocialAccount $a): bool => self::recipientId($a) === $user->id)
            ->map(fn (SocialAccount $a): array => self::describe($a))
            ->filter(fn (array $d): bool => $d['state'] !== 'ok')
            ->values()->all();
    }

    /** @return int reminders sent */
    public static function run(): int
    {
        $sent = 0;
        foreach (self::channels() as $account) {
            $d = self::describe($account);
            $stage = match ($d['state']) {
                'dropped' => 'dropped',
                'expiring' => 'expiring-'.collect(self::WARN_DAYS)->sort()->first(fn (int $days): bool => $d['days_left'] <= $days),
                default => null,
            };
            $to = self::recipientId($account);
            $user = $to ? User::query()->find($to) : null;
            if ($stage === null || $user === null || filled($user->deleted_at ?? null)) {
                continue;
            }

            // Once per stage per connection: reconnecting starts a new expiry date and a new key.
            $key = "tantra:reconnect:{$account->id}:{$stage}:".($account->token_expires_at?->toDateString() ?? 'none');
            if (! Cache::add($key, true, now()->addDays(70))) {
                continue;
            }

            Mail::to($user)->queue(new ReconnectChannel($account->workspace?->name ?? 'your workspace', self::label($account), $d['state'], $d['days_left']));
            $sent++;
        }

        return $sent;
    }

    /** @return Collection<int, SocialAccount> LinkedIn channels in workspaces that have Tantra settings */
    private static function channels(): Collection
    {
        return SocialAccount::query()
            ->whereIn('workspace_id', WorkspaceSettings::query()->select('workspace_id'))
            ->where('platform', Platform::LinkedIn)
            ->with('workspace.account')
            ->get();
    }

    private static function recipientId(SocialAccount $account): ?string
    {
        return ReviewGate::reviewOwnerIdFor($account->workspace_id, $account->workspace?->account?->owner_id);
    }

    /** @return array{id: string, workspace_id: string, workspace: ?string, name: string, state: string, days_left: ?int, expires_at: ?string} */
    public static function describe(SocialAccount $account): array
    {
        $expires = $account->token_expires_at;
        $daysLeft = $expires ? (int) floor(now()->diffInHours($expires, false) / 24) : null;

        $state = match (true) {
            $account->status !== Status::Connected => 'dropped',
            $expires !== null && $expires->isPast() => 'dropped',
            $daysLeft !== null && $daysLeft <= max(self::WARN_DAYS) && blank($account->refresh_token) => 'expiring',
            default => 'ok',
        };

        return [
            'id' => $account->id,
            'workspace_id' => $account->workspace_id,
            'workspace' => $account->workspace?->name,
            'name' => self::label($account),
            'state' => $state,
            'days_left' => $daysLeft === null ? null : max(0, $daysLeft),
            'expires_at' => $expires?->toIso8601String(),
        ];
    }

    private static function label(SocialAccount $account): string
    {
        return (string) ($account->display_name ?: $account->username ?: 'LinkedIn');
    }
}
