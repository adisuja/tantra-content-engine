<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\Account;
use App\Models\User;
use App\Tantra\Auth\TeamAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * tantra: make the shared team login an admin of every workspace on the main
 * Account, as it sees everything in usetantra.com and the Tantra CRM. Safe to
 * run again; Clerk sign in also does this at each sign in.
 */
class TeamAccountCommand extends Command
{
    protected $signature = 'tantra:team-account
        {email=team@growthclubpro.com : The team login}
        {--account= : Account id or its owner\'s email (default: the first Account)}
        {--owner : Also make this login the Account owner (billing, channel app keys); the previous owner stays an admin of every workspace}
        {--force : Hand over the Account without asking, and give team access to an existing user holding this email}';

    protected $description = 'Give the team login admin access to every workspace on the main Account';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("{$email} is not an email address.");

            return self::FAILURE;
        }

        $account = null;
        if (filled($ref = $this->option('account'))) {
            $account = Str::isUuid((string) $ref)
                ? Account::query()->find($ref)
                : User::query()->whereRaw('lower(email) = ?', [Str::lower((string) $ref)])->first()?->account;
            if ($account === null) {
                $this->components->error("No Account with id or owner {$ref}.");

                return self::FAILURE;
            }
        }

        if ($this->option('owner') && ! $this->option('force')
            && ! $this->confirm("Make {$email} the Account owner? The current owner becomes an admin of every workspace.")) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $user = TeamAccess::grant($email, $account, (bool) $this->option('owner'), adopt: (bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $workspaces = $user->accountWorkspaces()->orderBy('name')->pluck('name')->all();
        $this->components->info("{$user->email} is an admin of every workspace.");
        $this->components->twoColumnDetail('Workspaces', implode(', ', $workspaces) ?: 'none yet');
        $this->components->twoColumnDetail('Account owner', $user->isAccountOwner() ? 'yes' : 'no (use --owner)');

        return self::SUCCESS;
    }
}
