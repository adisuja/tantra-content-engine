<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Team\TeammateWelcome;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * tantra: give a teammate a login to their own workspace (PRD 10.9). They join
 * through a "set your password" link, see the posts written for them, connect
 * their LinkedIn and approve. Until they have joined, Adi keeps approving.
 */
class InviteTeammateCommand extends Command
{
    protected $signature = 'tantra:invite
        {workspace : Workspace name or id}
        {email : The teammate\'s login email}
        {name : Full name}
        {--send : Email the welcome with the set-password link (otherwise only print the link)}';

    protected $description = 'Create a teammate login for their workspace and make them its review owner';

    public function handle(): int
    {
        $ref = (string) $this->argument('workspace');
        $workspace = (Str::isUuid($ref) ? Workspace::query()->whereKey($ref)->first() : null)
            ?? Workspace::query()->where('name', $ref)->first();
        if ($workspace === null) {
            $this->components->error("No workspace named or with id {$ref}.");

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null && $existing->account_id !== $workspace->account_id) {
            $this->components->error("{$email} belongs to another Account. Nothing was changed.");

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($existing, $email, $workspace): User {
            $user = $existing ?? User::query()->create([
                'name' => trim((string) $this->argument('name')),
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
                'account_id' => $workspace->account_id,
                'timezone' => 'Asia/Kolkata',
            ]);

            if (! $workspace->hasMember($user)) {
                $workspace->members()->attach($user->id, ['is_admin' => true, 'requires_approval' => false]);
            }
            if ($user->current_workspace_id === null) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            WorkspaceSettings::query()->updateOrCreate(['workspace_id' => $workspace->id], ['review_owner_user_id' => $user->id]);

            return $user;
        });

        $link = URL::temporarySignedRoute('tantra.join', now()->addDays(14), ['user' => $user->id]);

        if ($this->option('send')) {
            Mail::to($user)->send(new TeammateWelcome($user->name, $workspace->name, $link));
            $this->components->info("Welcome email sent to {$email}.");
        } else {
            $this->components->info("{$email} can join {$workspace->name}. Link (14 days): {$link}");
        }

        return self::SUCCESS;
    }
}
