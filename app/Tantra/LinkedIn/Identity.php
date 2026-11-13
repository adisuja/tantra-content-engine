<?php

declare(strict_types=1);

namespace App\Tantra\LinkedIn;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\WorkspaceSettings;

/**
 * tantra: how a person or the page looks in a preview, read from what LinkedIn
 * (or X) gave us, never from text typed into a batch:
 *
 * - the LinkedIn page: its Zernio channel (name, logo, followers);
 * - a person: their LinkedIn identity read through Unipile (name, headline,
 *   photo), else their connected LinkedIn channel, else their Engine name;
 * - X: the connected X channel (name, handle, picture).
 *
 * @phpstan-type Shape array{name: string, headline: string, avatar: ?string, kind: 'person'|'company', followers_line: ?string, x_handle: ?string, profile_url: ?string}
 */
final class Identity
{
    /** @var array<string, array<string, mixed>|null> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * By profile key (the batch's "adi", "tantra", ...), within an Account.
     *
     * @return Shape|null
     */
    public static function forKey(string $accountId, string $key, ?string $platform = null): ?array
    {
        if ($key === '') {
            return null;
        }

        return self::$memo["key|{$accountId}|{$key}|{$platform}"] ??= (function () use ($accountId, $key, $platform): ?array {
            $workspaceId = WorkspaceSettings::query()->where('brand_profile_key', $key)
                ->whereHas('workspace', fn ($q) => $q->where('account_id', $accountId))->value('workspace_id');

            return $workspaceId ? self::forWorkspace((string) $workspaceId, $platform) : null;
        })();
    }

    /**
     * @param  ?string  $platform  the post's network: linkedin, linkedin_page or x
     * @return Shape|null
     */
    public static function forWorkspace(string $workspaceId, ?string $platform = null): ?array
    {
        return self::$memo["ws|{$workspaceId}|{$platform}"] ??= (function () use ($workspaceId, $platform): ?array {
            $channels = SocialAccount::query()->where('workspace_id', $workspaceId)->orderBy('created_at')->get();
            $settings = WorkspaceSettings::query()->find($workspaceId);
            $owner = $settings?->review_owner_user_id ? User::query()->find($settings->review_owner_user_id) : null;
            $profile = $owner ? MemberProfile::query()->find($owner->id) : null;

            $page = $channels->first(fn (SocialAccount $c): bool => in_array($c->platform, [Platform::ZernioLinkedInPage, Platform::LinkedInPage], true));
            $x = $channels->first(fn (SocialAccount $c): bool => $c->platform === Platform::X);
            $linkedin = $channels->first(fn (SocialAccount $c): bool => in_array($c->platform, [Platform::LinkedIn, Platform::UnipileLinkedIn], true));

            if ($platform === 'x' && $x !== null) {
                return self::shape(
                    name: $x->display_name ?: ($profile?->linkedin_name ?? $owner?->name ?? ''),
                    avatar: $x->avatar_url ?: $profile?->linkedinPictureUrl() ?: $owner?->photo_url,
                    xHandle: $x->username,
                    url: $x->username ? "https://x.com/{$x->username}" : null,
                );
            }

            if ($page !== null && ($platform === 'linkedin_page' || ($platform === null && $linkedin === null))) {
                $followers = data_get($page->meta, 'linkedin_followers');

                return self::shape(
                    name: (string) $page->display_name,
                    avatar: $page->avatar_url,
                    kind: 'company',
                    followersLine: is_numeric($followers) ? number_format((int) $followers).' followers' : null,
                    url: data_get($page->meta, 'linkedin_profile_url'),
                );
            }

            if ($platform === 'linkedin_page') {
                return null; // the page is not connected yet: never show a person as the page
            }

            if ($profile?->linkedin_name === null && $linkedin === null && $owner === null) {
                return null;
            }

            return self::shape(
                name: $profile?->linkedin_name ?: $linkedin?->display_name ?: ($owner?->name ?? ''),
                headline: (string) ($profile?->linkedin_headline ?? ''),
                avatar: $profile?->linkedinPictureUrl() ?: $linkedin?->avatar_url ?: $owner?->photo_url,
                url: $profile?->linkedin_profile_url,
            );
        })();
    }

    /** @return Shape */
    private static function shape(string $name, string $headline = '', ?string $avatar = null, string $kind = 'person', ?string $followersLine = null, ?string $xHandle = null, ?string $url = null): array
    {
        return ['name' => $name, 'headline' => $headline, 'avatar' => $avatar ?: null, 'kind' => $kind, 'followers_line' => $followersLine, 'x_handle' => $xHandle, 'profile_url' => $url];
    }
}
