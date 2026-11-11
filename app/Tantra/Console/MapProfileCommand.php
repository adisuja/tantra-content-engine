<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Console\Command;

/** tantra: link a workspace to an engine profile key (posts.json `profile`) and set its review owner. */
class MapProfileCommand extends Command
{
    protected $signature = 'tantra:map-profile
        {workspace : Workspace name or id}
        {profile : Engine profile key, e.g. adi or tantra}
        {--owner= : Email of the review owner (default: the Account owner)}
        {--timezone= : Default time zone for this workspace}';

    protected $description = 'Map an engine profile to a workspace and set who approves its posts';

    public function handle(): int
    {
        $ref = (string) $this->argument('workspace');
        $workspace = Workspace::query()->whereKey($ref)->first() ?? Workspace::query()->where('name', $ref)->first();

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
}
