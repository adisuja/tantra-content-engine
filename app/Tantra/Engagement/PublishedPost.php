<?php

declare(strict_types=1);

namespace App\Tantra\Engagement;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Notify\Notify;
use App\Tantra\Notify\SendToCrm;
use App\Tantra\Review\EngagementAsset;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Database\Eloquent\Collection;
use Ramsey\Uuid\Uuid;

/**
 * tantra: a published post becomes a watched post in Tantra's Engagement (One Tantra R1, Adi 15 Nov). When a post
 * goes live the Engine sends engine.post_published to the CRM intake (POST /intake/engine) with data.post: the post id,
 * link, platform, the account that published it and, for a giveaway, the EngagementAsset record. data.notify is false:
 * the event is for Engagement only and starts no notification (the Engine still sends its own "post published" email
 * until that email moves to the CRM). The CRM creates or updates one source per post (external id engine:<post id>),
 * switched on, with its follow ups in test mode. The contract: tantra-crm docs/crm/ENGAGEMENT_ENGINE_POSTS.md.
 *
 * The event id is derived from the post id, so a retry or a second call for the same post is stored once.
 */
final class PublishedPost
{
    /** The Engine platforms Engagement reads (the CRM refuses the rest with a reason; they are not sent at all). */
    public const PLATFORMS = [
        Platform::LinkedIn, Platform::UnipileLinkedIn, Platform::LinkedInPage, Platform::ZernioLinkedInPage,
        Platform::X, Platform::Facebook, Platform::Instagram, Platform::InstagramFacebook, Platform::Threads,
        Platform::YouTube, Platform::Bluesky, Platform::Mastodon, Platform::Telegram, Platform::Discord,
    ];

    public static function enabled(): bool
    {
        return Notify::crmConfigured() && (bool) config('tantra.crm.engagement.posts', true);
    }

    public static function watchable(Post $post): bool
    {
        return $post->status === PostStatus::Published
            && in_array($post->platform, self::PLATFORMS, true)
            && filled($post->platform_url);
    }

    /** Queue the event for one post. Returns false when there is nothing to send (off, not published, no link). */
    public static function send(Post $post, string $kind = 'published'): bool
    {
        if (! self::enabled() || ! self::watchable($post)) {
            return false;
        }
        $userId = $post->user_id ?? $post->workspace?->account?->owner_id;
        if ($userId === null) {
            return false;
        }

        SendToCrm::dispatch(
            self::eventId($post, $kind),
            'engine.post_published',
            (string) $userId,
            ['notify' => false, 'post' => self::payload($post)],
            null,
            $post->workspace?->name,
            $post->platform_url,
            'Open the post',
        );

        return true;
    }

    /** A stable uuid per post and kind (a live publish and a backfill are separate events). */
    public static function eventId(Post $post, string $kind = 'published'): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "tantra-engine:post_{$kind}:{$post->id}")->toString();
    }

    /** @return array<string, mixed> data.post */
    public static function payload(Post $post): array
    {
        $account = $post->socialAccount;
        $item = $post->post_group_id !== null ? ReviewItem::query()->where('post_group_id', $post->post_group_id)->first() : null;
        $asset = $item !== null ? EngagementAsset::record($item, $post->platform_url) : null;
        $text = trim((string) $post->content);

        return [
            'id' => (string) $post->id,
            // One Content app PRD 3.1: the review item this post came from. One item fans out to one post per network, and the CRM
            // binds the item's follow-up set to every source with this id.
            'review_item_id' => $item?->id !== null ? (string) $item->id : null,
            'url' => $post->platform_url,
            'platform' => $post->platform?->value,
            'published_at' => $post->published_at?->toIso8601ZuluString(),
            'title' => $text === '' ? null : mb_substr(strtok($text, "\n") ?: $text, 0, 200),
            'keyword' => $asset['resource']['keyword'] ?? null,
            'workspace' => $post->workspace?->name,
            'account' => array_filter([
                'id' => $account?->id,
                'username' => $account?->username,
                'display_name' => $account?->display_name ?? $post->platform_name,
                'unipile_account_id' => self::unipileAccount($post),
            ], fn (mixed $v): bool => $v !== null && $v !== ''),
            'asset' => $asset,
        ];
    }

    /** The Unipile account behind a LinkedIn post: the channel itself (Unipile LinkedIn), else the author's own. */
    private static function unipileAccount(Post $post): ?string
    {
        if ($post->platform === Platform::UnipileLinkedIn && filled($post->socialAccount?->platform_user_id)) {
            return (string) $post->socialAccount->platform_user_id;
        }
        if (in_array($post->platform, [Platform::LinkedIn, Platform::UnipileLinkedIn], true) && $post->user_id !== null) {
            $id = MemberProfile::query()->whereKey($post->user_id)->value('unipile_account_id');

            return filled($id) ? (string) $id : null;
        }

        return null;
    }

    /**
     * The posts a backfill covers: published in the last $days days, on a platform Engagement reads, with a link.
     *
     * @return Collection<int, Post>
     */
    public static function recent(int $days = 30): Collection
    {
        return Post::query()
            ->where('status', PostStatus::Published)
            ->whereIn('platform', self::PLATFORMS)
            ->whereNotNull('platform_url')
            ->where('published_at', '>=', now()->subDays($days))
            ->with(['socialAccount', 'workspace'])
            ->orderBy('published_at')
            ->get();
    }
}
