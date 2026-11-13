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
 * Clerk user signs in to the Engine user with the same verified email (linked
 * by clerk_user_id from then on). The team login is granted every workspace
 * on each sign in. Anyone else gets "ask your admin for access".
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

        $user = User::query()->where('clerk_user_id', $clerkId)->first();
        $email = $user?->email ?? self::verifiedEmail($claims);

        if ($email !== null && TeamAccess::isTeamEmail($email)) {
            $user = TeamAccess::grant($email);
        }

        $user ??= $email === null ? null : User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();
        if ($user === null) {
            return ['user' => null, 'email' => $email];
        }

        // An Engine user already linked to a different Clerk user is not taken over.
        if ($user->clerk_user_id !== null && $user->clerk_user_id !== $clerkId) {
            return ['user' => null, 'email' => $email];
        }

        if ($user->clerk_user_id === null) {
            $user->forceFill(['clerk_user_id' => $clerkId])->save();
        }

        return ['user' => $user, 'email' => $email];
    }

    /**
     * The person's verified primary email: from the session token when its
     * template carries one, else from Clerk's Backend API (CLERK_SECRET_KEY).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function verifiedEmail(array $claims): ?string
    {
        foreach (['email', 'primary_email_address', 'email_address'] as $claim) {
            if (is_string($claims[$claim] ?? null) && filter_var($claims[$claim], FILTER_VALIDATE_EMAIL)) {
                return Str::lower($claims[$claim]);
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
