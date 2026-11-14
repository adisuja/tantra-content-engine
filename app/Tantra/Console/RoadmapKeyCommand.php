<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\AccessToken;
use App\Models\User;
use App\Tantra\Roadmap\Roadmap;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * tantra: issues an API key with the roadmap:write scope, for an agent or a
 * teammate that moves and edits roadmap cards. The key shows in that person's
 * Settings > API keys, where it can be revoked.
 */
class RoadmapKeyCommand extends Command
{
    protected $signature = 'tantra:roadmap-key
        {email : Login email of the person the key acts as}
        {--name=Roadmap agent : Name shown in Settings > API keys}';

    protected $description = 'Issue an API key that may edit the public roadmap (roadmap:write) and print it once';

    public function handle(): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->components->error('No user with that email.');

            return self::FAILURE;
        }

        $result = $user->createToken((string) $this->option('name'), [Roadmap::SCOPE]);
        AccessToken::query()->whereKey($result->token->id)->first()?->forceFill([
            'workspace_id' => $user->current_workspace_id,
            'expires_at' => null,
        ])->saveQuietly();

        $this->components->info('Roadmap key for '.$user->email.'. Shown once, store it now:');
        $this->line($result->accessToken);

        return self::SUCCESS;
    }
}
