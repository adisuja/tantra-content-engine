<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Analytics\MetricSnapshot;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    WorkspaceSettings::query()->create(['workspace_id' => $result['workspace']->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    SocialAccount::factory()->create(['workspace_id' => $result['workspace']->id, 'platform' => Platform::LinkedIn]);

    // Two batches; carousels do far better than text for Adi.
    $posts = [];
    foreach (['3' => ['C1' => 'carousel', 'T1' => 'text', 'T2' => 'text'], '4' => ['C2' => 'carousel', 'T3' => 'text', 'T4' => 'text']] as $batch => $items) {
        foreach ($items as $key => $format) {
            $posts[$batch][] = ['engine_post_id' => $key, 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-16', 'time' => '08:30', 'tz' => 'UTC', 'text' => "Post {$key}.", 'format' => $format];
        }
    }
    foreach ($posts as $batch => $list) {
        ImportBatch::execute($this->owner, ['engine_batch_id' => (string) $batch, 'posts' => $list]);
    }

    foreach (['C1' => 120, 'C2' => 100, 'T1' => 20, 'T2' => 30, 'T3' => 25, 'T4' => 15] as $key => $reactions) {
        $item = ReviewItem::query()->where('engine_post_id', $key)->sole();
        $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();
        $post->forceFill(['status' => PostStatus::Published, 'published_at' => now()->subDays(3)])->saveQuietly();
        MetricSnapshot::query()->create(['post_id' => $post->id, 'review_item_id' => $item->id, 'source' => 'unipile', 'captured_at' => now(), 'reactions' => $reactions, 'comments' => 0, 'shares' => 0]);
    }
});

it('shows each person\'s numbers, best posts and what works for them', function () {
    $this->actingAs($this->owner)->get(route('tantra.insights', ['days' => 7]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/insights/Index')
            ->where('insights.days', 7)
            ->where('insights.people.0.profile', 'adi')
            ->where('insights.people.0.posts', 6)
            ->where('insights.people.0.engagement', 310)
            ->where('insights.people.0.impressions', null)
            ->where('insights.best.0.key', 'C1')
            ->where('insights.formula.0.winners.0.kind', 'format')
            ->where('insights.formula.0.winners.0.value', 'carousel')
            ->where('insights.formula.0.weak_signal', true));
});

it('falls back to 30 days for an unknown range', function () {
    $this->actingAs($this->owner)->get(route('tantra.insights', ['days' => 12]))
        ->assertInertia(fn (Assert $page) => $page->where('insights.days', 30));
});
