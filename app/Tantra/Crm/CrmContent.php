<?php

declare(strict_types=1);

namespace App\Tantra\Crm;

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Engagement\EngagementChannels;
use App\Tantra\Provision\ProvisionTantraTeam;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * tantra: what the Tantra app's native Content screens (Ideas, Drafts, Calendar) share (tantra-crm
 * docs/crm/PRD_CONTENT_NATIVE.md). A Tantra team id maps explicitly to Engine workspaces (ENGINE_CHANNELS_WORKSPACES),
 * an unmapped team gets nothing and never a default workspace, and the person acting is found by email inside the post's workspace.
 *
 * M1 (open access, 23 Nov): every route here is called server to server with the CRM key, so the Tantra app is the authority on who is
 * on the team. A teammate the CRM names who is not yet in the mapped workspace joins it as a member at once (actorOrJoin), never as an
 * admin, and never by taking a user that belongs to another Engine account.
 */
final class CrmContent
{
    /**
     * The Engine workspaces this Tantra team maps to.
     *
     * @return list<string>
     */
    public static function workspaceIds(?string $crmWorkspace): array
    {
        return EngagementChannels::engineWorkspaces($crmWorkspace);
    }

    /** The person with this email who belongs to the workspace (its owner or a member), or null. */
    public static function actor(Workspace $workspace, ?string $email): ?User
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        return User::query()->whereRaw('lower(email) = ?', [$email])->get()
            ->first(fn (User $user): bool => $workspace->user_id === $user->id || $workspace->members()->whereKey($user->id)->exists());
    }

    /** The person acting in this mapped workspace, added to it as a member when the Tantra app names someone it does not know yet. */
    public static function actorOrJoin(Workspace $workspace, ?string $email): ?User
    {
        return self::actor($workspace, $email) ?? self::join($workspace, $email);
    }

    /**
     * The first mapped workspace of this Tantra team the person belongs to, or the first mapped workspace once they join it.
     *
     * @param  list<string>  $workspaceIds  in mapping order
     * @return array{0: Workspace, 1: User}|null
     */
    public static function actorInTeam(array $workspaceIds, ?string $email): ?array
    {
        if ($workspaceIds === []) {
            return null;
        }
        $workspaces = Workspace::query()->whereIn('id', $workspaceIds)->get()
            ->sortBy(fn (Workspace $w): int|false => array_search($w->id, $workspaceIds, true))->values();
        foreach ($workspaces as $workspace) {
            if (($user = self::actor($workspace, $email)) !== null) {
                return [$workspace, $user];
            }
        }
        $first = $workspaces->first();
        $user = $first === null ? null : self::join($first, $email);

        return $user === null ? null : [$first, $user];
    }

    /**
     * Adds the person to the workspace as a member (not an admin, posts go out without approval): their Engine user is made on
     * the workspace's account, or an existing user with that email and no account (or the same account) is used. Null when the
     * email is empty or invalid, or already belongs to a user of another Engine account: that user is never moved.
     */
    public static function join(Workspace $workspace, ?string $email): ?User
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $workspace->account_id === null) {
            return null;
        }

        $attempt = function () use ($workspace, $email): ?User {
            return DB::transaction(function () use ($workspace, $email): ?User {
                $user = User::query()->whereRaw('lower(email) = ?', [$email])->orderBy('created_at')->orderBy('id')->first();
                if ($user !== null && $user->account_id !== null && $user->account_id !== $workspace->account_id) {
                    return null;
                }
                $user ??= User::query()->create([
                    'name' => self::nameFor($email),
                    'email' => $email,
                    'password' => Str::random(64),
                    'email_verified_at' => now(),
                    'account_id' => $workspace->account_id,
                    'locale' => Locale::DEFAULT,
                    'time_format' => TimeFormat::forLocale(Locale::DEFAULT),
                    'week_starts_on' => WeekStart::DEFAULT,
                    'timezone' => 'Asia/Kolkata',
                ]);
                if ($user->account_id === null) {
                    $user->forceFill(['account_id' => $workspace->account_id])->save();
                }
                if ($workspace->user_id !== $user->id) {
                    ProvisionTantraTeam::attach($workspace, $user, 'member');
                }
                Log::info('tantra.crm.actor: teammate joined from the Tantra app', ['user' => $user->id, 'workspace' => $workspace->id]);

                return $user->refresh();
            });
        };

        try {
            $user = $attempt();
        } catch (UniqueConstraintViolationException) {
            // Two first calls for the same person raced: the other one made the user, so this one joins with it.
            $user = $attempt();
        }
        if ($user === null) {
            Log::warning('tantra.crm.actor: email belongs to another Engine account; not joined', ['workspace' => $workspace->id]);
        }

        return $user;
    }

    /** True when this email is held by an Engine user of another account, so the person cannot join this team's workspace. */
    public static function heldElsewhere(Workspace $workspace, ?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && User::query()->whereRaw('lower(email) = ?', [$email])
            ->whereNotNull('account_id')->where('account_id', '!=', $workspace->account_id)->exists();
    }

    /** "priya.raman@x.com" becomes "Priya Raman"; the person changes it in their profile later. */
    private static function nameFor(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::of($local)->replaceMatches('/[._\-+]+/', ' ')->trim()->title()->limit(80, '')->value() ?: $email;
    }

    /** @param LengthAwarePaginator<int, mixed> $page */
    public static function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
