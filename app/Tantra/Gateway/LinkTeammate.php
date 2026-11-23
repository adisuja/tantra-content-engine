<?php

declare(strict_types=1);

namespace App\Tantra\Gateway;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Crm\CrmContent;

/**
 * tantra: M1 and M2 open access. A Tantra teammate the engine has never seen joins one workspace by their email
 * (CrmContent::join, as a member) and their Clerk user id is linked to that engine user.
 *
 * Null, so the caller keeps its refusal, when there is no email, the Clerk id is malformed or already held by a user,
 * the email belongs to another Account (CrmContent::join) or to a user already linked to another Clerk id.
 */
final class LinkTeammate
{
    public const CLERK = '/^user_[A-Za-z0-9]{10,64}$/';

    public static function join(Workspace $workspace, string $clerkUserId, ?string $email): ?User
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || preg_match(self::CLERK, $clerkUserId) !== 1
            || User::query()->where('clerk_user_id', $clerkUserId)->exists()) {
            return null;
        }
        $held = User::query()->whereRaw('lower(email) = ?', [$email])
            ->whereNotNull('clerk_user_id')->where('clerk_user_id', '!=', $clerkUserId)->exists();
        if ($held) {
            return null;
        }
        $user = CrmContent::join($workspace, $email);
        if ($user === null || ($user->clerk_user_id !== null && $user->clerk_user_id !== $clerkUserId)) {
            return null;
        }
        if ($user->clerk_user_id === null) {
            $user->forceFill(['clerk_user_id' => $clerkUserId])->save();
        }

        return $user;
    }
}
