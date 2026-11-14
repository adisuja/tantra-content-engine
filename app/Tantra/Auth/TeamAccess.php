<?php

declare(strict_types=1);

namespace App\Tantra\Auth;

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * tantra: the shared team login (team@growthclubpro.com) sees everything, as in
 * usetantra.com and the Tantra CRM: it joins the main Account and is an admin
 * of every workspace, so the sidebar lists every teammate's channels. Safe to
 * run again: it only adds what is missing. Owner-only screens (billing, channel
 * app keys) stay with the Account owner unless $makeOwner hands the Account over.
 */
class TeamAccess
{
    public static function isTeamEmail(?string $email): bool
    {
        return $email !== null && in_array(Str::lower(trim($email)), (array) config('tantra.clerk.team_emails', []), true);
    }

    /**
     * The main Account: the first one created on this instance.
     */
    public static function mainAccount(): ?Account
    {
        return Account::query()->oldest('created_at')->oldest('id')->first();
    }

    /**
     * From the command ($clerkId null), an existing user holding the email that
     * was never given team access is adopted only with $adopt (--force).
     *
     * @throws RuntimeException when there is no Account yet, the email belongs to another Account, or adoption needs --force.
     */
    public static function grant(string $email, ?Account $account = null, bool $makeOwner = false, string $name = 'Tantra Team', ?string $clerkId = null, bool $adopt = false): User
    {
        $email = Str::lower(trim($email));
        $account ??= self::mainAccount();
        if ($account === null) {
            throw new RuntimeException('There is no Account yet. Run php artisan tantra:create-owner first.');
        }

        return DB::transaction(function () use ($email, $account, $makeOwner, $name, $clerkId, $adopt): User {
            // From Clerk: the Engine user linked to this Clerk user, else the one
            // with the Clerk-verified email, if it is not linked to someone else.
            $user = $clerkId === null ? null : User::query()->where('clerk_user_id', $clerkId)->first();
            $user ??= ClerkLogin::byEmail($email);
            if ($user !== null && $clerkId !== null && ! ClerkLogin::canLink($user, $clerkId)) {
                throw new RuntimeException("{$email} is linked to another Clerk user. Run php artisan tantra:clerk-unlink {$email} first.");
            }
            if ($user !== null && $user->account_id !== null && $user->account_id !== $account->id) {
                throw new RuntimeException("{$email} belongs to another Account. Nothing was changed.");
            }
            if ($user !== null && $clerkId === null && ! $adopt && $user->tantra_team_access_at === null) {
                $since = $user->created_at?->toDateString() ?? 'an unknown date';
                $workspaces = $user->workspaces()->count();
                throw new RuntimeException("{$email} is already an Engine user: {$user->name} (id {$user->id}, since {$since}, in {$workspaces} workspaces). Run again with --force to give this user team access. Nothing was changed.");
            }

            $user ??= User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
                'account_id' => $account->id,
                'locale' => Locale::DEFAULT,
                'time_format' => TimeFormat::forLocale(Locale::DEFAULT),
                'week_starts_on' => WeekStart::DEFAULT,
                'timezone' => 'Asia/Kolkata',
            ]);
            if ($user->account_id === null) {
                $user->forceFill(['account_id' => $account->id])->save();
            }
            if ($clerkId !== null && $user->clerk_user_id !== $clerkId) {
                $user->forceFill(['clerk_user_id' => $clerkId])->save();
            }
            if ($user->tantra_team_access_at === null) {
                $user->forceFill(['tantra_team_access_at' => now()])->save();
            }

            $workspaces = $account->workspaces()->orderBy('name')->get();
            self::adminOfEvery($user, $workspaces);

            if ($user->current_workspace_id === null && $workspaces->isNotEmpty()) {
                $user->forceFill(['current_workspace_id' => $workspaces->first()->id])->save();
            }

            if ($makeOwner && $account->owner_id !== $user->id) {
                // The previous owner keeps full access as an admin of every workspace.
                $previous = $account->owner;
                if ($previous !== null) {
                    self::adminOfEvery($previous, $workspaces);
                }
                $account->forceFill(['owner_id' => $user->id])->save();
            }

            return $user->refresh();
        });
    }

    /**
     * @param  iterable<Workspace>  $workspaces
     */
    private static function adminOfEvery(User $user, iterable $workspaces): void
    {
        $memberships = DB::table('user_workspace')->where('user_id', $user->id)->pluck('workspace_id')->all();
        foreach ($workspaces as $workspace) {
            if (in_array($workspace->id, $memberships, true)) {
                $workspace->members()->updateExistingPivot($user->id, ['is_admin' => true, 'requires_approval' => false]);
            } else {
                $workspace->members()->attach($user->id, ['is_admin' => true, 'requires_approval' => false]);
            }
        }
    }
}
