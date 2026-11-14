<?php

declare(strict_types=1);

namespace App\Tantra\Rebrand;

use App\Models\User;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * tantra: start a rebrand (the person, the Account owner or an admin), and
 * apply the headline the person picks (only that person, review M12). The
 * Engine reads the profile through their own Unipile account and hands it to
 * the agent; the headline is written with Unipile's profile update and read
 * back to check it took. The About text, banner and featured items are applied
 * by hand: the page gives each one ready to copy.
 */
final class Rebrands
{
    public const TANTRA = 'Tantra helps B2B sales, marketing and RevOps teams run outbound across every channel from one platform, and teaches the funnel and AI RevOps system behind it in a 3-day workshop.';

    public static function start(User $by, User $person): Rebrand
    {
        $open = Rebrand::query()->where('user_id', $person->id)->whereIn('status', ['writing', 'proposed'])->first();
        if ($open !== null) {
            return $open;
        }
        $profile = MemberProfile::query()->find($person->id);
        $me = null;
        if ($profile?->unipile_account_id && UnipileClient::configured()) {
            try {
                $me = app(UnipileClient::class)->profile((string) $profile->unipile_account_id)['data'];
            } catch (Throwable) {
                $me = null;
            }
        }

        return DB::transaction(function () use ($by, $person, $profile, $me): Rebrand {
            $rebrand = Rebrand::query()->create(['account_id' => $person->account_id, 'user_id' => $person->id, 'requested_by' => $by->id, 'status' => 'writing']);
            $job = AgentJobs::enqueue('rebrand', [
                'person' => [
                    'name' => $profile?->linkedin_name ?? $person->name,
                    'headline' => $profile?->linkedin_headline,
                    'about' => $me['summary'] ?? $me['bio'] ?? null,
                    'location' => $me['location'] ?? null,
                    'followers' => $profile?->linkedin_followers,
                    'profile_url' => $profile?->linkedin_profile_url,
                ],
                'role' => ['expertise' => $profile?->expertise],
                'tantra' => self::TANTRA,
                'examples' => MemberProfile::query()->whereNotNull('linkedin_headline')->where('user_id', '!=', $person->id)->limit(5)->pluck('linkedin_headline')->all(),
            ], $by, null, $rebrand);
            $rebrand->forceFill(['agent_job_id' => $job->id])->save();

            return $rebrand;
        });
    }

    /** @return array{applied: bool, message: string} */
    public static function applyHeadline(User $person, Rebrand $rebrand, string $headline): array
    {
        abort_unless($rebrand->user_id === $person->id, 403, 'Only the person themself can change their profile.');
        $account = MemberProfile::query()->whereKey($person->id)->value('unipile_account_id');
        if ($account === null || ! UnipileClient::configured()) {
            throw ValidationException::withMessages(['headline' => 'Your LinkedIn is not connected through Unipile yet. Copy the headline and paste it on LinkedIn.']);
        }
        $client = app(UnipileClient::class);
        $sent = $client->updateProfile((string) $account, ['headline' => $headline]);
        $now = $sent ? ($client->profile((string) $account)['data'] ?? null) : null;
        $took = $now !== null && trim((string) ($now['headline'] ?? $now['description'] ?? '')) === trim($headline);

        $rebrand->forceFill([
            'applied' => [...($rebrand->applied ?? []), 'headline' => ['text' => $headline, 'confirmed' => $took, 'at' => now()->toIso8601String()]],
            'status' => $took ? 'applied' : $rebrand->status, 'applied_at' => $took ? now() : $rebrand->applied_at,
        ])->save();
        if ($took) {
            MemberProfile::query()->whereKey($person->id)->update(['linkedin_headline' => $headline]);
        }

        return $took
            ? ['applied' => true, 'message' => 'Your LinkedIn headline is updated.']
            : ['applied' => false, 'message' => 'LinkedIn did not take it from here. Copy the headline and paste it on your profile.'];
    }
}
