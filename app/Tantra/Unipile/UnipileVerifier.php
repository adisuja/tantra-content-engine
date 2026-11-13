<?php

declare(strict_types=1);

namespace App\Tantra\Unipile;

use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;

/** tantra: TryPost's connection check for a Unipile channel: the account must still be running. */
final class UnipileVerifier
{
    public static function verify(SocialAccount $account): bool
    {
        if (! UnipileClient::configured()) {
            throw new TokenExpiredException('LinkedIn publishing is not set up on the server.');
        }

        $list = app(UnipileClient::class)->allAccounts();
        $found = collect($list['items'])->first(fn (array $a): bool => ($a['id'] ?? null) === $account->platform_user_id);
        if ($found === null && ! $list['complete']) {
            return true; // beyond the first page: unknown, never treated as gone
        }
        if ($found === null) {
            throw new TokenExpiredException('This LinkedIn account is no longer connected in Tantra.');
        }
        if (UnipileClient::statusOf($found) !== 'connected') {
            throw new TokenExpiredException('Reconnect this LinkedIn account in Tantra.');
        }

        return true;
    }
}
