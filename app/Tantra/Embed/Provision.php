<?php

declare(strict_types=1);

namespace App\Tantra\Embed;

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Auth\ClerkLogin;
use App\Tantra\Auth\TeamAccess;
use App\Tantra\Links\CrmTracking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: every Tantra teammate gets into Content (One Tantra S-12, 15 Nov).
 *
 * A person who opens Content inside Tantra with no Engine login used to see "Content is not turned on". Behind
 * TANTRA_EMBED_PROVISION they now get a login on that first visit, when:
 *  1. Clerk verified their email (the embed session already checked the token and read the email), and
 *  2. that email is a member of the Tantra workspace the Engine's own CRM key (TANTRA_CRM_API_KEY) belongs to,
 *     read from the CRM's GET /api/me with that key (the team mapping the Engagement tab already uses: the main
 *     Account is the Tantra team). Kept 5 minutes, so a teammate added in Tantra gets in within minutes.
 * They join the main Account and the team's Engine workspace (TANTRA_EMBED_PROVISION_WORKSPACE, an id or a name)
 * as a member who is not an admin; M1 (23 Nov): their posts go out without approval. Tantra stores nothing new.
 *
 * Never: a person whose email is not in the Tantra team, an email held by another Account, or an Engine user
 * linked to another Clerk user. Each of those keeps the "not turned on" screen.
 */
final class Provision
{
    private const MEMBERS_TTL = 300;

    public static function enabled(): bool
    {
        return Embed::enabled() && (bool) config('tantra.embed.provision.enabled') && CrmTracking::configured();
    }

    /**
     * The Engine user for a verified email from Tantra's team, created on the first visit; null when the email
     * is not a teammate's, the team cannot be read, or the email is taken.
     */
    public static function forTeammate(string $email, string $clerkId): ?User
    {
        if (! self::enabled()) {
            return null;
        }
        $email = Str::lower(trim($email));
        $members = self::teamEmails();
        if ($members === null || ! in_array($email, $members, true)) {
            return null;
        }

        $account = TeamAccess::mainAccount();
        $workspace = self::workspace();
        if ($account === null || $workspace === null || $workspace->account_id !== $account->id) {
            Log::warning('tantra.embed.provision: no team workspace; set TANTRA_EMBED_PROVISION_WORKSPACE', ['workspace' => config('tantra.embed.provision.workspace')]);

            return null;
        }

        $existing = ClerkLogin::byEmail($email);
        if ($existing !== null && (($existing->account_id !== null && $existing->account_id !== $account->id) || ! ClerkLogin::canLink($existing, $clerkId))) {
            return null;
        }

        return DB::transaction(function () use ($existing, $email, $clerkId, $account, $workspace): User {
            $user = $existing ?? User::query()->create([
                'name' => self::nameFor($email),
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
                'account_id' => $account->id,
                'locale' => Locale::DEFAULT,
                'time_format' => TimeFormat::forLocale(Locale::DEFAULT),
                'week_starts_on' => WeekStart::DEFAULT,
                'timezone' => 'Asia/Kolkata',
            ]);
            $user->forceFill(array_filter([
                'account_id' => $user->account_id === null ? $account->id : null,
                'clerk_user_id' => $user->clerk_user_id !== $clerkId ? $clerkId : null,
            ]))->save();

            if (! $workspace->hasMember($user)) {
                $workspace->members()->attach($user->id, ['is_admin' => false, 'requires_approval' => false]);
            }
            if ($user->current_workspace_id === null) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }
            Log::info('tantra.embed.provision: teammate added', ['user' => $user->id, 'workspace' => $workspace->id]);

            return $user->refresh();
        });
    }

    /**
     * The emails of the Tantra workspace the CRM key belongs to (lower case), or null when the CRM cannot say.
     *
     * @return list<string>|null
     */
    public static function teamEmails(): ?array
    {
        $cached = Cache::get('tantra:embed:team-emails');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('tantra.crm.url'), '/'))
                ->withToken((string) config('tantra.crm.api_key'))
                ->acceptJson()->timeout(8)->get('/api/me');
        } catch (Throwable $e) {
            Log::warning('tantra.embed.provision: the CRM did not answer', ['reason' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('tantra.embed.provision: the CRM refused /api/me', ['status' => $response->status()]);

            return null;
        }

        $emails = [];
        foreach ((array) $response->json('members', []) as $member) {
            $value = is_array($member) ? ($member['email'] ?? null) : null;
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emails[] = Str::lower(trim($value));
            }
        }
        $emails = array_values(array_unique($emails));
        Cache::put('tantra:embed:team-emails', $emails, self::MEMBERS_TTL);

        return $emails;
    }

    private static function workspace(): ?Workspace
    {
        $ref = trim((string) config('tantra.embed.provision.workspace'));
        if ($ref === '') {
            return null;
        }
        $account = TeamAccess::mainAccount();

        return (Str::isUuid($ref) ? Workspace::query()->whereKey($ref)->first() : null)
            ?? Workspace::query()->where('account_id', $account?->id)->where('name', $ref)->orderBy('created_at')->first();
    }

    /** "priya.raman@x.com" becomes "Priya Raman"; the person changes it in their profile later. */
    private static function nameFor(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::of($local)->replaceMatches('/[._\-+]+/', ' ')->trim()->title()->limit(80, '')->value() ?: $email;
    }
}
