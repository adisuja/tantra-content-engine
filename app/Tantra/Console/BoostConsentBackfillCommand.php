<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use Illuminate\Console\Command;

/**
 * tantra: One Content app PRD 3.5. Joining a team is consent to boost, so every Tantra member (an engine user linked to a Clerk user)
 * who has never decided gets consent now. Only members with neither consent nor an opt out are touched: someone who turned boost
 * off stays off. --dry-run counts them and changes nothing. Safe to run again.
 */
class BoostConsentBackfillCommand extends Command
{
    protected $signature = 'tantra:boost-consent-backfill {--dry-run : Count the members and change nothing}';

    protected $description = 'Give boost consent to Tantra members who have neither consent nor an opt out (PRD 3.5)';

    public function handle(): int
    {
        $undecided = MemberProfile::query()->whereNull('boost_consent_at')->whereNull('boost_opted_out_at');
        $withoutProfile = User::query()->whereNotNull('clerk_user_id')
            ->whereNotIn('id', MemberProfile::query()->select('user_id'));

        $profiles = (clone $undecided)->count();
        $missing = (clone $withoutProfile)->count();

        if ($this->option('dry-run')) {
            $this->components->info("{$profiles} member profiles and {$missing} members without a profile would get consent. Nothing was changed (dry run).");

            return self::SUCCESS;
        }

        $now = now();
        $updated = $undecided->update(['boost_consent_at' => $now, 'updated_at' => $now]);
        $created = 0;
        $withoutProfile->select('id')->chunkById(200, function ($users) use ($now, &$created): void {
            foreach ($users as $user) {
                MemberProfile::query()->firstOrCreate(['user_id' => $user->id], ['boost_consent_at' => $now]);
                $created++;
            }
        });

        $this->components->info("Consent set for {$updated} member profiles; {$created} profiles created with consent.");

        return self::SUCCESS;
    }
}
