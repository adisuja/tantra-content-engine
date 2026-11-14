<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use App\Models\Post;
use App\Models\Workspace;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Str;

/** tantra: who a piece belongs to and what it is called, for its links. */
final class Piece
{
    public function __construct(
        public readonly string $memberKey,
        public readonly ?string $workspaceId,
        public readonly string $ref,
        public readonly string $name,
        public readonly string $platform,
        /** The medium for a link in the body: post, carousel or giveaway. */
        public readonly string $bodyMedium,
    ) {}

    public static function ofReviewItem(ReviewItem $item, ?string $platform = null): self
    {
        $payload = (array) ($item->payload ?? []);
        $name = (string) ($item->short ?: ($payload['short'] ?? $item->engine_post_id));

        return new self(
            self::memberKey($item->workspace_id, $payload['profile'] ?? null),
            $item->workspace_id,
            'ri:'.$item->id,
            $name,
            LinkRules::platform($platform ?? $item->platform),
            self::bodyMedium((string) $item->format, filled($payload['resource'] ?? null) || filled($payload['messages'] ?? null)),
        );
    }

    public static function ofPost(Post $post): self
    {
        $item = filled($post->post_group_id)
            ? ReviewItem::query()->where('post_group_id', $post->post_group_id)->first()
            : null;
        if ($item !== null) {
            return self::ofReviewItem($item, $post->platform?->value ?? $item->platform);
        }

        // A LinkedIn carousel is a document post: it carries a document title.
        $carousel = filled(data_get($post->meta, 'document_title')) || $post->content_type?->value === 'pinterest_carousel';

        return new self(
            self::memberKey($post->workspace_id, null),
            $post->workspace_id,
            'post:'.$post->id,
            Str::words(strip_tags((string) $post->content), 6, '') ?: 'post',
            LinkRules::platform($post->platform?->value ?? 'linkedin'),
            $carousel ? 'carousel' : 'post',
        );
    }

    public static function ofIdea(IdeaMeta $meta): self
    {
        $source = filled($meta->source_review_item_id) ? ReviewItem::query()->find($meta->source_review_item_id) : null;
        $title = (string) ($meta->idea?->title ?? '');
        $name = $source !== null
            ? (string) ($source->short ?: $source->engine_post_id)
            : (trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $title)) ?: (string) $meta->external_key);
        $preview = (array) ($meta->preview ?? []);

        return new self(
            self::memberKey($meta->idea?->workspace_id, $meta->profile_key),
            $meta->idea?->workspace_id,
            // An idea made from a reviewed piece is the same piece on another network.
            $source !== null ? 'ri:'.$source->id : 'idea:'.$meta->idea_id,
            $name,
            LinkRules::platform((string) $meta->platform),
            self::bodyMedium((string) $meta->format, filled($preview['resource'] ?? null)),
        );
    }

    public static function memberKey(?string $workspaceId, ?string $profile): string
    {
        if (filled($profile)) {
            return Str::slug((string) $profile);
        }
        if ($workspaceId === null) {
            return 'team';
        }
        $key = WorkspaceSettings::query()->where('workspace_id', $workspaceId)->value('brand_profile_key');

        return Str::slug((string) ($key ?: Workspace::query()->whereKey($workspaceId)->value('name') ?: 'team'));
    }

    private static function bodyMedium(string $format, bool $giveaway): string
    {
        $f = strtolower($format);

        return match (true) {
            $giveaway || str_contains($f, 'giveaway') => 'giveaway',
            str_contains($f, 'carousel') || str_contains($f, 'document') || str_contains($f, 'pdf') => 'carousel',
            default => 'post',
        };
    }
}
