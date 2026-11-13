<?php

declare(strict_types=1);

namespace App\Tantra\LinkedIn;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: identities come from LinkedIn itself (Adi, 2026-11-13), the way a
 * proper app does it. Nothing about a person is typed in by hand.
 *
 * - A LinkedIn account connected in Unipile is matched to the Engine user with
 *   the same name (one each way); Adi can change a match on the Team page.
 * - From that account: name, headline, photo, profile URL, member id and
 *   followers, read from Unipile's users/me. The photo is copied to our storage
 *   (LinkedIn's picture links expire) and becomes the person's Engine photo.
 * - The Tantra page: name, logo, profile URL and followers from Zernio.
 *
 * New accounts are picked up every 15 minutes, identities refresh daily, and a
 * match made on the Team page is read at once. A page channel gets its name and
 * logo from Zernio when it is connected; this keeps them current.
 */
final class LinkedInSync
{
    public const STALE_HOURS = 24;

    /** @return array{matched: int, people: int, pages: int, disconnected: int} */
    public static function run(UnipileClient $unipile, ZernioClient $zernio, ?string $onlyUserId = null, bool $force = false): array
    {
        $out = ['matched' => 0, 'people' => 0, 'pages' => 0, 'disconnected' => 0];

        if (UnipileClient::configured()) {
            try {
                $out['matched'] = self::match($unipile);
            } catch (Throwable $e) {
                report($e); // Unipile is down: read the people already matched anyway
            }

            $profiles = MemberProfile::query()->whereNotNull('unipile_account_id')
                ->when($onlyUserId, fn ($q) => $q->whereKey($onlyUserId))
                ->when(! $force && ! $onlyUserId, fn ($q) => $q->where(fn ($w) => $w->whereNull('linkedin_synced_at')->orWhere('linkedin_synced_at', '<', now()->subHours(self::STALE_HOURS))))
                ->get();
            foreach ($profiles as $i => $profile) {
                if ($i > 0) {
                    usleep(1_500_000); // users/me is rate limited
                }
                try {
                    $state = self::person($unipile, $profile);
                } catch (Throwable $e) {
                    report($e);
                    $state = 'busy';
                }
                $out['people'] += $state === 'synced' ? 1 : 0;
                $out['disconnected'] += $state === 'disconnected' ? 1 : 0;
            }
        }

        if (ZernioClient::configured() && $onlyUserId === null) {
            $out['pages'] = self::pages($zernio);
        }

        return $out;
    }

    /** Match LinkedIn accounts in Unipile to Engine users by exact name, one to one. */
    public static function match(UnipileClient $unipile): int
    {
        $norm = fn (?string $n): string => Str::of((string) $n)->lower()->squish()->value();
        $accounts = collect($unipile->accounts())
            ->filter(fn (array $a): bool => Str::upper((string) ($a['type'] ?? $a['provider'] ?? 'LINKEDIN')) === 'LINKEDIN' && filled($a['name'] ?? null));
        $taken = MemberProfile::query()->whereNotNull('unipile_account_id')->pluck('unipile_account_id')->all();

        // Keep each known account's connection state current.
        foreach ($accounts as $a) {
            MemberProfile::query()->where('unipile_account_id', $a['id'])->update(['unipile_status' => self::status($a)]);
        }

        $matched = 0;
        $users = User::query()->get()->groupBy(fn (User $u): string => $norm($u->name));
        foreach ($accounts->groupBy(fn (array $a): string => $norm($a['name'])) as $name => $same) {
            $people = $users->get($name);
            if ($same->count() !== 1 || $people === null || $people->count() !== 1) {
                continue; // no match, or not one to one: Adi decides on the Team page
            }
            $account = $same->first();
            $user = $people->first();
            if (in_array($account['id'], $taken, true) || MemberProfile::query()->whereKey($user->id)->whereNotNull('unipile_account_id')->exists()) {
                continue;
            }

            MemberProfile::query()->updateOrCreate(['user_id' => $user->id], [
                'unipile_account_id' => $account['id'],
                'unipile_name' => $account['name'],
                'unipile_match_method' => 'name',
                'unipile_status' => self::status($account),
            ]);
            $matched++;
        }

        return $matched;
    }

    /** A changed or removed match: forget the identity that came from the old account. */
    public static function forget(MemberProfile $profile): void
    {
        $profile->forceFill([
            'unipile_status' => null, 'linkedin_member_id' => null, 'linkedin_public_identifier' => null,
            'linkedin_name' => null, 'linkedin_headline' => null, 'linkedin_profile_url' => null,
            'linkedin_followers' => null, 'linkedin_picture_source' => null, 'linkedin_synced_at' => null,
        ])->save();
    }

    /** @return 'synced'|'disconnected'|'busy' */
    public static function person(UnipileClient $unipile, MemberProfile $profile): string
    {
        $answer = $unipile->profile((string) $profile->unipile_account_id);
        if ($answer['data'] === null) {
            if (in_array($answer['status'], [401, 403, 404], true)) {
                $profile->forceFill(['unipile_status' => 'disconnected'])->save();

                return 'disconnected';
            }

            return 'busy';
        }

        $me = $answer['data'];
        $name = trim((string) ($me['display_name'] ?? trim(($me['first_name'] ?? '').' '.($me['last_name'] ?? ''))));
        $source = collect(['public_picture_url_large', 'public_picture_url', 'profile_picture_url'])
            ->map(fn (string $k) => $me[$k] ?? null)->first(fn ($v) => is_string($v) && str_starts_with($v, 'https://'));

        $profile->forceFill([
            'unipile_status' => 'connected',
            'linkedin_member_id' => $me['id'] ?? $me['provider_id'] ?? $profile->linkedin_member_id,
            'linkedin_public_identifier' => $me['public_identifier'] ?? null,
            'linkedin_name' => $name ?: null,
            'linkedin_headline' => filled($me['description'] ?? null) ? Str::limit((string) $me['description'], 490, '') : null,
            'linkedin_profile_url' => $me['profile_url'] ?? null,
            'linkedin_followers' => is_numeric($me['followers_count'] ?? null) ? (int) $me['followers_count'] : null,
            'linkedin_synced_at' => now(),
        ]);

        // LinkedIn's picture links expire: keep our own copy, and make it their Engine photo.
        if ($source !== null && self::pictureKey($source) !== self::pictureKey((string) $profile->linkedin_picture_source)) {
            $path = "tantra/linkedin/{$profile->user_id}.jpg";
            try {
                $image = Http::timeout(20)->get($source)->throw()->body();
                Storage::put($path, $image, 'public');
                $profile->linkedin_picture_path = $path;
                $profile->linkedin_picture_source = $source;
                self::usePhoto($profile, $image);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $profile->save();

        return 'synced';
    }

    /** The Tantra page (and any other Zernio LinkedIn page): name, logo, link and followers from Zernio. */
    public static function pages(ZernioClient $zernio): int
    {
        $channels = SocialAccount::query()->where('platform', Platform::ZernioLinkedInPage)->get();
        if ($channels->isEmpty()) {
            return 0;
        }

        try {
            $accounts = collect($zernio->accounts())->keyBy(fn (array $a): string => (string) ($a['_id'] ?? $a['id'] ?? ''));
        } catch (Throwable) {
            return 0;
        }

        $synced = 0;
        foreach ($channels as $channel) {
            $a = $accounts->get((string) $channel->platform_user_id);
            if ($a === null) {
                continue;
            }

            $avatar = $channel->avatar_url;
            $logo = $a['profilePicture'] ?? null;
            $meta = (array) ($channel->meta ?? []);
            if (is_string($logo) && str_starts_with($logo, 'https://') && self::pictureKey($logo) !== self::pictureKey((string) ($meta['linkedin_logo_source'] ?? ''))) {
                try {
                    $path = "tantra/linkedin/page-{$channel->id}.jpg";
                    Storage::put($path, Http::timeout(20)->get($logo)->throw()->body(), 'public');
                    $avatar = Storage::url($path);
                    $meta['linkedin_logo_source'] = $logo;
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $channel->forceFill([
                'display_name' => $a['displayName'] ?? $a['username'] ?? $channel->display_name,
                'avatar_url' => $avatar,
                'meta' => [...$meta,
                    'linkedin_followers' => is_numeric($a['followersCount'] ?? null) ? (int) $a['followersCount'] : ($meta['linkedin_followers'] ?? null),
                    'linkedin_profile_url' => $a['profileUrl'] ?? ($meta['linkedin_profile_url'] ?? null),
                    'linkedin_synced_at' => now()->toIso8601ZuluString(),
                ],
            ])->save();
            $synced++;
        }

        return $synced;
    }

    /** @param array<string, mixed> $account */
    private static function status(array $account): string
    {
        $raw = is_string($account['status'] ?? null) ? $account['status'] : (string) data_get($account, 'sources.0.status', '');

        return in_array(Str::upper($raw), ['OK', 'RUNNING', 'CONNECTED', 'CREATION_SUCCESS', 'RECONNECTED', 'SYNC_SUCCESS'], true) ? 'connected' : 'disconnected';
    }

    /** The picture's identity without its expiring signature, to tell a new photo from a refreshed link. */
    private static function pictureKey(string $url): string
    {
        return (string) Str::before($url, '?');
    }

    private static function usePhoto(MemberProfile $profile, string $image): void
    {
        $user = $profile->user;
        if ($user === null) {
            return;
        }
        // Their own uploaded photo wins; a photo that came from LinkedIn is replaced by the new one.
        $current = $user->getFirstMedia('avatar');
        $fromLinkedIn = $current === null || data_get($current->meta, 'source') === 'linkedin' || $current->original_filename === 'linkedin-photo.jpg';
        if (! $fromLinkedIn) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tantra-li');
        file_put_contents($tmp, $image);
        try {
            $user->addMediaFromPath($tmp, 'linkedin-photo.jpg', 'avatar', ['source' => 'linkedin'], null, 'image/jpeg');
        } finally {
            @unlink($tmp);
        }
    }
}
