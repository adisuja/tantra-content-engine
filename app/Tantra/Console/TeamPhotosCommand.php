<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: each teammate's LinkedIn picture as their Engine profile photo, so
 * the review previews show real faces (PRD 10.2). Read through their own
 * connected Unipile account (the confirmed match, else the account with their
 * exact name); people who already have a photo are left alone.
 */
class TeamPhotosCommand extends Command
{
    protected $signature = 'tantra:team-photos {--force : replace photos that are already set}';

    protected $description = 'Set each teammate\'s Engine photo from their LinkedIn picture';

    public function handle(UnipileClient $unipile): int
    {
        if (! UnipileClient::configured()) {
            $this->components->warn('Unipile is not set up.');

            return self::SUCCESS;
        }

        $accounts = collect($unipile->accounts());
        foreach (User::query()->orderBy('name')->get() as $user) {
            if ($user->has_photo && ! $this->option('force')) {
                continue;
            }

            $accountId = MemberProfile::query()->find($user->id)?->unipile_account_id
                ?? $accounts->first(fn (array $a): bool => Str::lower(trim((string) ($a['name'] ?? ''))) === Str::lower(trim((string) $user->name)))['id'] ?? null;
            if ($accountId === null) {
                $this->line("{$user->name}: no LinkedIn account on Unipile");

                continue;
            }

            $me = $unipile->me((string) $accountId);
            $url = collect(['profile_picture_url_large', 'profile_picture_url', 'picture_url', 'public_picture_url'])
                ->map(fn (string $k) => data_get($me, $k))->first(fn ($v) => is_string($v) && str_starts_with($v, 'https://'));
            if ($url === null) {
                $this->line("{$user->name}: ".($me === null ? 'LinkedIn did not answer (try again later)' : 'no picture on LinkedIn'));

                continue;
            }

            try {
                $image = Http::timeout(20)->get($url)->throw()->body();
                $tmp = tempnam(sys_get_temp_dir(), 'tantra-photo');
                file_put_contents($tmp, $image);
                $user->addMediaFromPath($tmp, 'linkedin-photo.jpg', 'avatar', [], null, 'image/jpeg');
                @unlink($tmp);
                $this->line("{$user->name}: photo set");
            } catch (Throwable $e) {
                $this->line("{$user->name}: could not save the picture ({$e->getMessage()})");
            }
        }

        return self::SUCCESS;
    }
}
