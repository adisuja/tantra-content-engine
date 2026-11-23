<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Auth\SocialConnectionController;
use App\Models\User;
use App\Support\Social\PendingConnection;
use App\Tantra\Crm\CrmPaths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * tantra: One Content app PRD 4.1, the account picker in the CRM. When a network's sign in comes back, /connect/{platform}/finish no
 * longer shows the engine's confirmation page: the connection (with its tokens) is kept here, server side, under a random token for
 * fifteen minutes, and the person goes to https://crm.usetantra.com/content/channels?finish=<token>. The CRM then calls
 *
 *   GET  /api/gateway/tantra/connect/{token}/candidates   the accounts the sign in offered (never their tokens), or why there are none
 *   POST /api/gateway/tantra/connect/{token}/select       {identities: [key, ...]}: connect those, under the rights held now
 *
 * Only the person who signed in can read or use the token.
 */
final class AccountPicker
{
    public const int TTL_MINUTES = PendingConnection::TTL_MINUTES;

    public static function enabled(?Platform $platform): bool
    {
        return (bool) config('tantra.crm.account_picker') || CrmConnectLink::channelsReturn($platform) !== null;
    }

    /** Keep the current connection for the CRM and say where the person goes now. */
    public static function handoff(User $user, Platform $platform): string
    {
        $pending = PendingConnection::current();
        // A connection started from a CRM link (the referral invite page, say) says where the CRM goes once it is connected.
        $link = CrmConnectLink::owns($platform) ? CrmConnectLink::take(CrmConnectLink::CONNECTED) : null;
        $token = Str::random(48);
        Cache::put(self::key($token), [
            'user_id' => (string) $user->id,
            'platform' => $platform->value,
            'pending' => $pending?->platform() === $platform ? $pending->toArray() : null,
            'return_url' => $link,
        ], now()->addMinutes(self::TTL_MINUTES));
        // The CRM owns the rest of the round trip; the engine's own session forgets it.
        PendingConnection::forget();
        CrmConnectLink::forget();

        return CrmPaths::finish($token);
    }

    /** @return array{user_id: string, platform: string, pending: array<string, mixed>|null, return_url?: string|null}|null */
    public static function load(string $token, User $user): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }
        $kept = Cache::get(self::key($token));

        return is_array($kept) && hash_equals((string) ($kept['user_id'] ?? ''), (string) $user->id) ? $kept : null;
    }

    /**
     * What the picker shows: the state, the reason when there is nothing to pick, and the offered accounts.
     *
     * @param  array{platform: string, pending: array<string, mixed>|null, return_url?: string|null}  $kept
     * @return array<string, mixed>
     */
    public static function candidates(array $kept): array
    {
        $platform = Platform::from($kept['platform']);
        $pending = self::restore($kept);
        $state = match (true) {
            $pending === null => 'expired',
            $pending->failure() === 'cancelled' => 'cancelled',
            $pending->failure() !== null => 'error',
            $pending->isReady() => 'select',
            default => 'expired',
        };
        $locked = $pending?->lockedIdentityKeys() ?? [];

        return [
            'platform' => $platform->value,
            'network' => $platform->network(),
            'state' => $state,
            'reason' => $state === 'error' ? (ConnectFailures::reason($platform, $pending?->failure()) ?? __("accounts.connect.errors.{$pending?->failure()}")) : null,
            'reconnecting' => $pending?->reconnectId() !== null,
            // Where the CRM goes once connected, when the connection started from one of its links (?network=&result=connected).
            'return_url' => $kept['return_url'] ?? null,
            'identities' => $state === 'select' ? array_map(fn (array $identity): array => [
                'key' => data_get($identity, 'key'),
                'platform' => data_get($identity, 'platform'),
                'name' => data_get($identity, 'name') ?? data_get($identity, 'username'),
                'username' => data_get($identity, 'username'),
                'avatar' => data_get($identity, 'avatar'),
                'type' => data_get($identity, 'type'),
                'locked' => in_array(data_get($identity, 'key'), $locked, true),
            ], $pending->identities()) : [],
        ];
    }

    /**
     * Connect the picked accounts. The token is used up on success.
     *
     * @param  array{platform: string, pending: array<string, mixed>|null}  $kept
     * @param  list<string>  $keys
     * @return list<array<string, mixed>> the connected channels
     */
    public static function select(string $token, User $user, array $kept, array $keys): array
    {
        $platform = Platform::from($kept['platform']);
        $pending = self::restore($kept);
        $allowed = array_values(array_diff($pending?->identityKeys() ?? [], $pending?->lockedIdentityKeys() ?? []));
        $bad = array_values(array_diff($keys, $allowed));
        if ($pending === null || ! $pending->isReady()) {
            throw new AccountPickerException('This sign in has expired. Connect the channel again.', 'expired');
        }
        if ($keys === [] || $bad !== []) {
            throw new AccountPickerException(__('accounts.connect.errors.identity_connected'), 'invalid_identity');
        }

        $accounts = SocialConnectionController::networkFor($platform)->finishPicked($user, $keys);
        Cache::forget(self::key($token));

        return $accounts->map(fn ($account): array => [
            'id' => (string) $account->id,
            'platform' => $account->platform->value,
            'name' => (string) ($account->display_name ?: $account->username),
            'username' => $account->username,
            'created' => (bool) $account->wasRecentlyCreated,
        ])->values()->all();
    }

    /** @param array{pending: array<string, mixed>|null} $kept */
    private static function restore(array $kept): ?PendingConnection
    {
        return is_array($kept['pending'] ?? null) ? PendingConnection::restore($kept['pending']) : null;
    }

    private static function key(string $token): string
    {
        return 'tantra:connect-pick:'.$token;
    }
}
