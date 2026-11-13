<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: every account connected in the Tantra app shows up as an Engine
 * channel, in the right person's workspace (Adi, 2026-11-13, PRD D1 to D4).
 *
 * Sources: Tantra's Unipile (all the team's accounts; the only one the Engine
 * can publish through) and each Tantra API key on the Connectors tab (that
 * person's accounts; matched to Unipile by network and name). A channel looks
 * the same whatever its source; the source lives only in `meta.provider`.
 *
 * Who: only the team. The production Unipile key also holds Tantra customers'
 * accounts, so an account becomes a channel only when it is matched to an
 * Engine teammate on the Team page, listed by a Tantra API key on the
 * Connectors tab, or named by Adi (`--person`). Everyone else is ignored.
 *
 * Where: a teammate's own workspace (named after them; never a shared one);
 * otherwise the workspace in the Tantra Account named after the first name.
 * A workspace that already has the person's LinkedIn connected directly keeps
 * that channel and gets no copy. Nothing is ever deleted: an account that
 * drops out of a complete Unipile list is marked disconnected.
 */
final class TeamChannels
{
    /** Unipile account types the Engine can publish to, and their channel platform. */
    private const PLATFORMS = ['LINKEDIN' => Platform::UnipileLinkedIn];

    /**
     * @param  list<string>  $people  names to place even with no Engine user or Tantra API key (Adi's explicit list)
     * @return array{created: int, updated: int, skipped: int, unplaced: list<string>, tantra_api: int, workspaces_created: list<string>, errors: list<string>}
     */
    public static function sync(UnipileClient $unipile, bool $createWorkspaces = false, array $people = []): array
    {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'unplaced' => [], 'tantra_api' => 0, 'workspaces_created' => [], 'errors' => []];

        if (! UnipileClient::configured()) {
            $out['errors'][] = 'Unipile is not configured.';

            return $out;
        }

        try {
            $list = $unipile->allAccounts();
        } catch (Throwable $e) {
            report($e);
            $out['errors'][] = 'Unipile could not be read: '.$e->getMessage();

            return $out;
        }
        $accounts = collect($list['items'])
            ->filter(fn (array $a): bool => isset(self::PLATFORMS[self::type($a)]) && filled($a['id'] ?? null))
            ->values();

        if (! $list['complete']) {
            $out['errors'][] = 'Unipile has more accounts than one page returns; accounts beyond it are not synced.';
        }

        $home = self::homeAccountId();
        // A name on more than one account (a stale duplicate, a namesake): place none of them.
        $nameCounts = $accounts->countBy(fn (array $a): string => self::norm($a['name'] ?? ''));
        $tantra = self::tantraApiAccounts($out);
        $named = collect($people)->map(fn (string $n): string => self::norm($n))->filter()->all();

        foreach ($accounts as $a) {
            $platform = self::PLATFORMS[self::type($a)];
            $status = UnipileClient::statusOf($a) === 'connected' ? Status::Connected : Status::Disconnected;
            $name = trim((string) ($a['name'] ?? ''));
            $tantraIds = $tantra->filter(fn (array $t): bool => self::norm($t['displayName'] ?? '') === self::norm($name) && strtoupper((string) ($t['channel'] ?? '')) === self::type($a))
                ->pluck('id')->filter()->values()->all();

            $channel = SocialAccount::query()->where('platform', $platform)->where('platform_user_id', $a['id'])->first();
            if ($channel !== null) {
                self::refresh($channel, $a, $status, $tantraIds);
                $out['updated']++;

                continue;
            }

            // Only the team: a teammate matched on the Team page, someone a Tantra API
            // key on the Connectors tab lists, or a name Adi gave. Never anyone else on the key.
            $teammate = self::teammateHome($a, $home);
            $listed = ($tantraIds !== [] || in_array(self::norm($name), $named, true)) && $nameCounts->get(self::norm($name), 0) === 1;
            if ($teammate === null && ! $listed) {
                continue;
            }

            $workspace = $teammate ?? self::workspaceNamed(self::firstName($name), $home);
            if ($workspace === null && $createWorkspaces && $listed && $status === Status::Connected && $name !== '' && $home !== null) {
                $workspace = self::createWorkspace(self::firstName($name), $home);
                $out['workspaces_created'][] = $workspace->name;
            }
            if ($workspace === null) {
                $out['unplaced'][] = $name ?: (string) $a['id'];

                continue;
            }
            // The person's LinkedIn is already connected directly in their workspace: keep that one.
            if (self::hasDirectLinkedIn($workspace, $teammate !== null ? null : $name)) {
                $out['skipped']++;

                continue;
            }

            $channel = SocialAccount::query()->create([
                'workspace_id' => $workspace->id,
                'platform' => $platform,
                'platform_user_id' => (string) $a['id'],
                'username' => '',
                'display_name' => $name ?: 'LinkedIn',
                'access_token' => 'unipile-managed',
                'status' => $status,
                'timezone' => self::timezone(),
                'posting_schedule' => self::weekdays('10:30'),
                'meta' => array_filter(['provider' => 'unipile', 'tantra_account_ids' => $tantraIds ?: null, 'placed_by' => $teammate !== null ? 'teammate' : 'listed']),
            ]);
            self::refresh($channel, $a, $status, $tantraIds);
            Log::info('tantra: channel added from Tantra', ['channel' => $channel->id, 'workspace' => $workspace->name, 'name' => $name]);
            $out['created']++;
        }

        // Accounts gone from Unipile: keep the channel, show it as disconnected.
        // Only from a list known to be complete and not empty: a partial or empty
        // read is never taken as people leaving.
        if (! $list['complete'] || $accounts->isEmpty()) {
            return $out;
        }
        $live = $accounts->pluck('id')->all();
        SocialAccount::query()->where('platform', Platform::UnipileLinkedIn)->whereNotIn('platform_user_id', $live)
            ->where('status', '!=', Status::Disconnected)->get()
            ->each(fn (SocialAccount $c) => $c->forceFill(['status' => Status::Disconnected, 'disconnected_at' => now()])->save());

        return $out;
    }

    /** The Engine user matched to this Unipile account on the Team page, and their own workspace. */
    public static function teammateHome(array $account, ?string $home): ?Workspace
    {
        $user = MemberProfile::query()->where('unipile_account_id', $account['id'] ?? '')->first()?->user;
        if ($user === null || ($home !== null && $user->account_id !== $home)) {
            return null;
        }

        return self::homeWorkspace($user);
    }

    /** A person's own workspace: the one named after them (Adi for Adithya). Never a shared one. */
    public static function homeWorkspace(User $user): ?Workspace
    {
        $workspaces = $user->workspaces()->where('workspaces.account_id', $user->account_id)->get();
        $first = self::norm(self::firstName((string) $user->name));
        $exact = $workspaces->filter(fn (Workspace $w): bool => self::norm($w->name) === $first);
        if ($exact->count() === 1) {
            return $exact->first();
        }
        $prefix = $workspaces->filter(fn (Workspace $w): bool => mb_strlen(self::norm($w->name)) >= 3 && str_starts_with($first, self::norm($w->name)));

        return $prefix->count() === 1 ? $prefix->first() : null;
    }

    private static function workspaceNamed(string $first, ?string $home): ?Workspace
    {
        if ($home === null || self::norm($first) === '') {
            return null;
        }
        $named = Workspace::query()->where('account_id', $home)->get()->filter(fn (Workspace $w): bool => self::norm($w->name) === self::norm($first));

        return $named->count() === 1 ? $named->first() : null;
    }

    /** The Tantra team's Account: the one holding the Tantra API connector, else the oldest. */
    private static function homeAccountId(): ?string
    {
        return AiConnector::query()->where('provider', 'tantra_api')->where('is_active', true)->orderBy('created_at')->value('account_id')
            ?? Workspace::query()->orderBy('created_at')->value('account_id');
    }

    /** @param list<string> $tantraIds */
    private static function refresh(SocialAccount $channel, array $a, Status $status, array $tantraIds): void
    {
        $profile = MemberProfile::query()->where('unipile_account_id', $a['id'])->first();
        $meta = (array) ($channel->meta ?? []);
        $fill = [
            'display_name' => $profile?->linkedin_name ?: (trim((string) ($a['name'] ?? '')) ?: $channel->display_name),
            'status' => $status,
            'meta' => array_filter([...$meta,
                'provider' => 'unipile',
                'tantra_account_ids' => $tantraIds ?: ($meta['tantra_account_ids'] ?? null),
                'profile_url' => $profile?->linkedin_profile_url ?: ($meta['profile_url'] ?? null),
            ], fn ($v): bool => $v !== null),
        ];
        if ($profile?->linkedin_picture_path) {
            $fill['avatar_url'] = $profile->linkedin_picture_path; // a storage path: the model turns it into a URL
        }
        if ($status === Status::Disconnected && $channel->status !== Status::Disconnected) {
            $fill['disconnected_at'] = now();
        }
        $channel->forceFill($fill)->save();
    }

    /** A direct LinkedIn in the workspace: any one for a teammate's own workspace, else one with the same name. */
    private static function hasDirectLinkedIn(Workspace $workspace, ?string $name): bool
    {
        return SocialAccount::query()->where('workspace_id', $workspace->id)->where('platform', Platform::LinkedIn)->get()
            ->contains(fn (SocialAccount $c): bool => $name === null || self::norm($c->display_name) === self::norm($name));
    }

    private static function createWorkspace(string $name, string $accountId): Workspace
    {
        $owner = Workspace::query()->where('account_id', $accountId)->orderBy('created_at')->first()?->owner
            ?? User::query()->where('account_id', $accountId)->orderBy('created_at')->firstOrFail();

        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = Workspace::query()->create(['name' => $name, 'account_id' => $owner->account_id, 'user_id' => $owner->id]);
            $workspace->members()->attach($owner->id, ['is_admin' => true, 'requires_approval' => false]);

            return $workspace;
        });
    }

    /** @param array{tantra_api: int, errors: list<string>} $out */
    private static function tantraApiAccounts(array &$out): Collection
    {
        $all = collect();
        foreach (AiConnector::query()->where('provider', 'tantra_api')->where('is_active', true)->get() as $connector) {
            try {
                $rows = (new TantraApiClient((string) $connector->secret, (string) ($connector->base_url ?: TantraApiClient::DEFAULT_URL)))->accounts();
                $all = $all->merge($rows);
                $out['tantra_api'] += count($rows);
                $connector->forceFill(['last_verified_at' => now(), 'last_error' => null])->save();
            } catch (Throwable $e) {
                $out['errors'][] = 'Tantra API: '.$e->getMessage();
                $connector->forceFill(['last_failed_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            }
        }

        return $all;
    }

    private static function timezone(): string
    {
        return (string) (SocialAccount::query()->whereNotNull('timezone')->where('platform', Platform::LinkedIn)->value('timezone') ?: 'America/New_York');
    }

    private static function weekdays(string $time): PostingSchedule
    {
        $schedule = PostingSchedule::empty();
        foreach ([1, 2, 3, 4, 5] as $day) {
            $schedule = $schedule->withTime($day, $time);
        }

        return $schedule;
    }

    private static function type(array $a): string
    {
        return Str::upper((string) ($a['type'] ?? $a['provider'] ?? ''));
    }

    private static function firstName(string $name): string
    {
        return (string) Str::of($name)->squish()->before(' ');
    }

    private static function norm(?string $s): string
    {
        return Str::of((string) $s)->lower()->squish()->value();
    }
}
