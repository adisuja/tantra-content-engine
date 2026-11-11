<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Post;
use App\Tantra\Review\ReviewChips; // tantra:
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A post in the shape the publish page cards read (see BuildPublishPageProps::decorate).
 * Its author carries only what a card shows, never the member's account data.
 *
 * @mixin Post
 */
class PostCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = [
            ...$this->resource->toArray(),
            'failure' => $this->resource->error_context === null ? null : [
                'category' => data_get($this->resource->error_context, 'category'),
                'failed_at' => data_get($this->resource->error_context, 'failed_at'),
            ],
            'tantra_review' => app(ReviewChips::class)->for($this->resource), // tantra: review chip
        ];

        if ($this->resource->relationLoaded('user')) {
            $card['user'] = $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'photo_url' => $this->user->photo_url,
            ];
        }

        return $card;
    }

    /**
     * @param  iterable<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    public static function cards(iterable $posts): array
    {
        $posts = collect($posts);
        app(ReviewChips::class)->prime($posts); // tantra: one query for the page's review chips

        return $posts->map(fn (Post $post): array => self::make($post)->resolve())->values()->all();
    }
}
