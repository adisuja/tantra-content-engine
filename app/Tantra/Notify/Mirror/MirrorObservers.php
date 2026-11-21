<?php

declare(strict_types=1);

namespace App\Tantra\Notify\Mirror;

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Analytics\MetricSnapshot;
use App\Tantra\Review\Models\ReviewItem;

/**
 * tantra: Speed P3. The model observers that feed the CRM's Content mirror (ContentChange): posts, review items (approval), social
 * accounts (channels) and metrics. Each one only names what changed; ContentChange queues it after commit, for mapped teams only.
 * Registered by TantraServiceProvider, so upstream's own observers stay as they are.
 */
final class MirrorObservers
{
    public static function register(): void
    {
        Post::created(fn (Post $post) => ContentChange::record($post->workspace_id, self::postCreatedKind($post), ContentChange::SUBJECT_POST, (string) $post->id));
        Post::updated(function (Post $post): void {
            $kind = self::postUpdatedKind($post);
            if ($kind !== null) {
                ContentChange::record($post->workspace_id, $kind, ContentChange::SUBJECT_POST, (string) $post->id);
            }
        });
        Post::deleted(fn (Post $post) => ContentChange::record($post->workspace_id, ContentChange::POST_DELETED, ContentChange::SUBJECT_POST, (string) $post->id));

        ReviewItem::saved(fn (ReviewItem $item) => ContentChange::record($item->workspace_id, ContentChange::REVIEW_CHANGED, ContentChange::SUBJECT_REVIEW, (string) $item->id));
        ReviewItem::deleted(fn (ReviewItem $item) => ContentChange::record($item->workspace_id, ContentChange::REVIEW_CHANGED, ContentChange::SUBJECT_REVIEW, (string) $item->id));

        SocialAccount::created(fn (SocialAccount $a) => ContentChange::record($a->workspace_id, ContentChange::CHANNEL_CONNECTED, ContentChange::SUBJECT_CHANNEL, (string) $a->id));
        SocialAccount::updated(function (SocialAccount $a): void {
            // Token refreshes save the account often; only what the channels list shows counts.
            if ($a->wasChanged(['status', 'display_name', 'username', 'platform', 'workspace_id'])) {
                ContentChange::record($a->workspace_id, ContentChange::CHANNEL_CONNECTED, ContentChange::SUBJECT_CHANNEL, (string) $a->id);
            }
        });
        SocialAccount::deleted(fn (SocialAccount $a) => ContentChange::record($a->workspace_id, ContentChange::CHANNEL_DISCONNECTED, ContentChange::SUBJECT_CHANNEL, (string) $a->id));

        MetricSnapshot::saved(fn (MetricSnapshot $s) => ContentChange::metricsRead(
            $s->post_id ? (string) Post::query()->withoutGlobalScopes()->whereKey($s->post_id)->value('workspace_id') : null,
        ));
        AnalyticsAccountDailySnapshot::saved(fn (AnalyticsAccountDailySnapshot $s) => ContentChange::metricsRead($s->workspace_id ? (string) $s->workspace_id : null));
        AnalyticsPublicationDailySnapshot::saved(fn (AnalyticsPublicationDailySnapshot $s) => ContentChange::metricsRead(
            $s->publication_id ? (string) AnalyticsPublication::query()->whereKey($s->publication_id)->value('workspace_id') : null,
        ));
    }

    public static function postCreatedKind(Post $post): string
    {
        return $post->status === PostStatus::Scheduled ? ContentChange::POST_SCHEDULED : ContentChange::POST_CREATED;
    }

    /** The kind for a saved change, or null when nothing the Content screens show moved. */
    public static function postUpdatedKind(Post $post): ?string
    {
        if ($post->wasChanged('deleted_at') && $post->getAttribute('deleted_at') !== null) {
            return ContentChange::POST_DELETED;
        }
        if ($post->wasChanged('status')) {
            return match ($post->status) {
                PostStatus::Scheduled => ContentChange::POST_SCHEDULED,
                PostStatus::Published => ContentChange::POST_PUBLISHED,
                PostStatus::Failed => ContentChange::POST_FAILED,
                default => ContentChange::POST_UPDATED,
            };
        }
        if ($post->wasChanged('publish_status')) {
            return match ($post->publish_status) {
                PublishStatus::Published => ContentChange::POST_PUBLISHED,
                PublishStatus::Failed => ContentChange::POST_FAILED,
                default => ContentChange::POST_UPDATED,
            };
        }
        $shown = ['content', 'platform', 'content_type', 'scheduled_at', 'published_at', 'display_name', 'display_username', 'platform_url', 'error_message', 'workspace_id'];

        return $post->wasChanged($shown) ? ContentChange::POST_UPDATED : null;
    }
}
