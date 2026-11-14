<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Auth\ClerkLogin;
use Illuminate\Console\Command;

/**
 * tantra: forget which Clerk user an Engine user signs in as, so the next Clerk
 * user with that verified email links to it (a new usetantra.com login).
 */
class ClerkUnlinkCommand extends Command
{
    protected $signature = 'tantra:clerk-unlink {email : The Engine user\'s email}';

    protected $description = 'Clear the Clerk user linked to an Engine user';

    public function handle(): int
    {
        $user = ClerkLogin::byEmail((string) $this->argument('email'));
        if ($user === null) {
            $this->components->error('No Engine user has that email.');

            return self::FAILURE;
        }

        if ($user->clerk_user_id === null) {
            $this->components->info("{$user->email} is not linked to a Clerk user.");

            return self::SUCCESS;
        }

        $user->forceFill(['clerk_user_id' => null])->save();
        $this->components->info("{$user->email} is unlinked. The next Clerk sign in with this verified email links again.");

        return self::SUCCESS;
    }
}
