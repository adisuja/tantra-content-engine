<?php

declare(strict_types=1);

namespace App\Tantra\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: who a verified Clerk session is in the Engine (2026-11-14).
 *
 * usetantra.com has no Clerk organizations: every Clerk user is a person, and
 * the Engine's own Account, workspaces and invites decide what they see. So a
 * Clerk user signs in to the Engine user linked to them (clerk_user_id), else
 * to the one with their Clerk-verified email. Team access is decided only by
 * that Clerk-verified email, never by the email stored on an Engine user,
 * which its owner can edit. Anyone else gets "ask your admin for access".
 */
class ClerkLogin
{
    public static function enabled(): bool
    {
        return (bool) config('tantra.clerk.enabled') && filled(config('tantra.clerk.publishable_key'));
    }

    /**
     * @param  array<string, mixed>  $claims  Verified by ClerkToken.
     * @return array{user: ?User, email: ?string}
     */
    public static function resolve(array $claims): array
    {
        $clerkId = (string) $claims['sub'];
        $email = self::verifiedEmail($claims);

        if ($email !== null && TeamAccess::isTeamEmail($email)) {
            return ['user' => TeamAccess::grant($email, clerkId: $clerkId), 'email' => $email];
        }

        $linked = User::query()->where('clerk_user_id', $clerkId)->first();
        if ($linked !== null) {
            return ['user' => $linked, 'email' => $email];
        }
        if ($email === null) {
            return ['user' => null, 'email' => null];
        }

        $user = self::byEmail($email);
        if ($user === null || ! self::canLink($user, $clerkId)) {
            return ['user' => null, 'email' => $email];
        }

        $user->forceFill(['clerk_user_id' => $clerkId])->save();

        return ['user' => $user, 'email' => $email];
    }

    public static function byEmail(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($email))])
            ->orderBy('created_at')->orderBy('id')->first();
    }

    /**
     * An Engine user takes this Clerk user when it has none yet, or when the
     * Clerk user it was linked to no longer exists (the caller has already
     * matched the new Clerk user's verified primary email to it).
     */
    public static function canLink(User $user, string $clerkId): bool
    {
        return $user->clerk_user_id === null
            || $user->clerk_user_id === $clerkId
            || self::clerkUserGone($user->clerk_user_id);
    }

    /**
     * True only when Clerk's Backend API answers 404 for the Clerk user.
     */
    public static function clerkUserGone(string $clerkId): bool
    {
        $secret = (string) config('tantra.clerk.secret_key');
        if ($secret === '') {
            return false;
        }

        try {
            return Http::timeout(8)->withToken($secret)->acceptJson()
                ->get(config('tantra.clerk.api_url').'/users/'.rawurlencode($clerkId))
                ->status() === 404;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The person's verified primary email: from the session token when its
     * template carries the email and email_verified === true, else from
     * Clerk's Backend API (CLERK_SECRET_KEY).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function verifiedEmail(array $claims): ?string
    {
        if (($claims['email_verified'] ?? null) === true) {
            foreach (['email', 'primary_email_address', 'email_address'] as $claim) {
                if (is_string($claims[$claim] ?? null) && filter_var($claims[$claim], FILTER_VALIDATE_EMAIL)) {
                    return Str::lower($claims[$claim]);
                }
            }
        }

        $secret = (string) config('tantra.clerk.secret_key');
        if ($secret === '') {
            return null;
        }

        try {
            $response = Http::timeout(8)->withToken($secret)->acceptJson()
                ->get(config('tantra.clerk.api_url').'/users/'.rawurlencode((string) $claims['sub']));
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $primaryId = $response->json('primary_email_address_id');
        foreach ((array) $response->json('email_addresses', []) as $address) {
            if (($address['id'] ?? null) === $primaryId && data_get($address, 'verification.status') === 'verified') {
                return Str::lower((string) $address['email_address']);
            }
        }

        return null;
    }
}
