<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * tantra: the founding and development team (PRD 25.4). Only these people see
 * Studio ops: jobs, their inputs and logs, Claude accounts, runners, presets.
 * Set by TANTRA_ADMIN_EMAILS; nobody is an admin by default.
 */
final class Admin
{
    public static function is(?User $user): bool
    {
        // A verified email only: anyone can type an address into their profile (review M2).
        if ($user === null || blank($user->email) || $user->email_verified_at === null) {
            return false;
        }

        return in_array(Str::lower((string) $user->email), self::emails(), true);
    }

    /** @return list<string> */
    public static function emails(): array
    {
        return array_values(array_filter(array_map(
            fn (string $e): string => Str::lower(trim($e)),
            (array) config('tantra.admin_emails', []),
        )));
    }
}
