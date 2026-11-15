<?php

declare(strict_types=1);

namespace App\Tantra\Crm;

use App\Models\Post;

/**
 * tantra: one post as the Tantra app's Drafts and Calendar read it: the fields of PostResource a person sees, in words and
 * ISO times, and nothing the Tantra app should not hold (no author, no group, no social account id).
 */
final class CrmPostPresenter
{
    /** @return array<string, mixed> */
    public static function present(Post $post): array
    {
        return [
            'id' => $post->id,
            'status' => $post->status->value,
            'content' => (string) $post->content,
            'platform' => $post->platform?->value,
            'content_type' => $post->content_type?->value,
            'scheduled_at' => $post->scheduled_at?->utc()->toIso8601String(),
            'published_at' => $post->published_at?->utc()->toIso8601String(),
            'display_name' => $post->display_name,
            'display_username' => $post->display_username,
            'platform_url' => $post->platform_url,
            'error_message' => $post->error_message,
            'labels' => $post->labels->map(fn ($label): array => ['name' => (string) $label->name])->values()->all(),
            'created_at' => $post->created_at?->utc()->toIso8601String(),
            'updated_at' => $post->updated_at?->utc()->toIso8601String(),
        ];
    }
}
