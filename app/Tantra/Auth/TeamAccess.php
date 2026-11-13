<?php

declare(strict_types=1);

namespace App\Tantra\Auth;

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Account;
use App\Models\User;
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
     * @throws RuntimeException when there is no Account yet or the email belongs to another Account.
     */
    public static function grant(string $email, ?Account $account = null, bool $makeOwner = false, string $name = 'Tantra Team'): User
    {
        $email = Str::lower(trim($email));
        $account ??= self::mainAccount();
        if ($account === null) {
            throw new RuntimeException('There is no Account yet. Run php artisan tantra:create-owner first.');
        }

        return DB::transaction(function () use ($email, $account, $makeOwner, $name): User {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($user !== null && $user->account_id !== null && $user->account_id !== $account->id) {
                throw new RuntimeException("{$email} belongs to another Account. Nothing was changed.");
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

            $workspaces = $account->workspaces()->orderBy('name')->get();
            $memberships = DB::table('user_workspace')->where('user_id', $user->id)->pluck('workspace_id')->all();
            foreach ($workspaces as $workspace) {
                if (in_array($workspace->id, $memberships, true)) {
                    $workspace->members()->updateExistingPivot($user->id, ['is_admin' => true, 'requires_approval' => false]);
                } else {
                    $workspace->members()->attach($user->id, ['is_admin' => true, 'requires_approval' => false]);
                }
            }

            if ($user->current_workspace_id === null && $workspaces->isNotEmpty()) {
                $user->forceFill(['current_workspace_id' => $workspaces->first()->id])->save();
            }

            if ($makeOwner && $account->owner_id !== $user->id) {
                $account->forceFill(['owner_id' => $user->id])->save();
            }

            return $user->refresh();
        });
    }
}
