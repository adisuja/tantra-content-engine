<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Actions\User\CreateUser;
use App\Actions\Workspace\CreateWorkspace;
use App\Models\User;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates the first owner of a self-hosted Engine, which has no public sign-up.
 *
 * Goes through upstream's CreateUser (Account, owner, first workspace in one
 * transaction), then renames that first workspace and adds the others.
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'tantra:create-owner
        {email : Login email of the Account owner}
        {name : Full name}
        {--workspace=* : Workspaces to create, the first becomes the default (default: Adi, Tantra)}
        {--timezone=Asia/Kolkata : The owner\'s time zone}';

    protected $description = 'Create the Account owner and their workspaces, and print a one-time password';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        $workspaces = array_values(array_unique(array_filter(array_map('trim', (array) $this->option('workspace')))));
        $workspaces = $workspaces === [] ? ['Adi', 'Tantra'] : $workspaces;

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            ['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error("A user with {$email} already exists. Nothing was changed.");

            return self::FAILURE;
        }

        $password = Str::password(24, symbols: false);

        $user = DB::transaction(function () use ($email, $name, $password, $workspaces): User {
            $user = CreateUser::execute([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
                'timezone' => (string) $this->option('timezone'),
            ]);

            $first = $user->workspaces()->firstOrFail();
            $first->update(['name' => $workspaces[0]]);

            foreach (array_slice($workspaces, 1) as $workspaceName) {
                CreateWorkspace::execute($user, ['name' => $workspaceName]);
            }

            $user->switchWorkspace($first->refresh());

            // tantra: each workspace maps to the engine profile of the same name
            // (Adi -> adi, Tantra -> tantra), approved by the owner.
            foreach ($user->workspaces()->get() as $workspace) {
                WorkspaceSettings::query()->firstOrCreate(
                    ['workspace_id' => $workspace->id],
                    ['brand_profile_key' => Str::lower(Str::slug($workspace->name)), 'review_owner_user_id' => $user->id],
                );
            }

            return $user;
        });

        $this->components->info("Owner created: {$user->email}");
        $this->components->twoColumnDetail('Workspaces', implode(', ', $workspaces));
        $this->components->twoColumnDetail('Password', $password);
        $this->components->warn('This password is shown once. Sign in and change it under Settings.');

        return self::SUCCESS;
    }
}
