<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Actions\Workspace\CreateWorkspace;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** tantra: link a workspace to an engine profile key (posts.json `profile`) and set its review owner. */
class MapProfileCommand extends Command
{
    protected $signature = 'tantra:map-profile
        {workspace : Workspace name or id}
        {profile : Engine profile key, e.g. adi or tantra}
        {--owner= : Email of the review owner (default: the Account owner)}
        {--timezone= : Default time zone for this workspace}
        {--create : Create the workspace, owned by the Account owner, when it does not exist}
        {--account= : With --create: email of anyone in the Account (default: the only Account)}';

    protected $description = 'Map an engine profile to a workspace and set who approves its posts';

    public function handle(): int
    {
        $ref = (string) $this->argument('workspace');
        $workspace = (Str::isUuid($ref) ? Workspace::query()->whereKey($ref)->first() : null)
            ?? Workspace::query()->where('name', $ref)->first();

        if ($workspace === null && $this->option('create')) {
            $workspace = $this->createWorkspace($ref);
        }

        if ($workspace === null) {
            $this->components->error("No workspace named or with id {$ref}.");

            return self::FAILURE;
        }

        $email = $this->option('owner');
        $owner = $email
            ? User::query()->where('email', strtolower((string) $email))->where('account_id', $workspace->account_id)->first()
            : User::query()->find($workspace->account?->owner_id);

        if ($owner === null) {
            $this->components->error('Review owner not found in this Account.');

            return self::FAILURE;
        }

        $profile = strtolower(trim((string) $this->argument('profile')));
        $taken = WorkspaceSettings::query()->where('brand_profile_key', $profile)->where('workspace_id', '!=', $workspace->id)->exists();

        if ($taken) {
            $this->components->error("Profile {$profile} is already mapped to another workspace.");

            return self::FAILURE;
        }

        WorkspaceSettings::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            array_filter([
                'brand_profile_key' => $profile,
                'review_owner_user_id' => $owner->id,
                'timezone' => $this->option('timezone'),
            ], fn ($v): bool => $v !== null),
        );

        $this->components->info("{$workspace->name} is now profile {$profile}, approved by {$owner->email}.");

        return self::SUCCESS;
    }

    /** A new workspace owned by the Account owner, whose current workspace stays as it was. */
    private function createWorkspace(string $name): ?Workspace
    {
        $email = $this->option('account');
        $accountId = $email
            ? User::query()->where('email', strtolower((string) $email))->value('account_id')
            : (Account::query()->count() === 1 ? Account::query()->value('id') : null);
        $owner = $accountId ? User::query()->find(Account::query()->whereKey($accountId)->value('owner_id')) : null;

        if ($owner === null) {
            $this->components->error('Name the Account with --account=<email>.');

            return null;
        }

        $current = $owner->current_workspace_id;
        $workspace = CreateWorkspace::execute($owner, ['name' => $name]);
        $owner->forceFill(['current_workspace_id' => $current])->save();
        $this->components->info("Created workspace {$name}.");

        return $workspace;
    }
}
