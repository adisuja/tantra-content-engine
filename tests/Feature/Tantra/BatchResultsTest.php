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

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];
    WorkspaceSettings::query()->create(['workspace_id' => $result['workspace']->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    SocialAccount::factory()->create(['workspace_id' => $result['workspace']->id, 'platform' => Platform::LinkedIn]);

    ImportBatch::execute($this->owner, ['engine_batch_id' => '4', 'posts' => [
        ['engine_post_id' => 'A1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-16', 'time' => '08:30', 'tz' => 'America/New_York', 'text' => 'One.', 'format' => 'carousel', 'pillar' => 'proof'],
        ['engine_post_id' => 'A2', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-17', 'time' => '08:30', 'tz' => 'America/New_York', 'text' => 'Two.', 'format' => 'carousel', 'pillar' => 'story'],
    ]]);

    $item = ReviewItem::query()->where('engine_post_id', 'A1')->sole();
    $this->post = Post::query()->where('post_group_id', $item->post_group_id)->sole();
    $this->post->forceFill(['status' => PostStatus::Published, 'published_at' => now()->subDay(), 'platform_url' => 'https://www.linkedin.com/feed/update/urn:li:share:1/'])->saveQuietly();
    MetricSnapshot::query()->create([
        'post_id' => $this->post->id, 'review_item_id' => $item->id, 'source' => 'unipile', 'captured_at' => now(),
        'reactions' => 30, 'comments' => 12, 'shares' => 2,
    ]);
});

it('returns the batch in the results.json shape, with a reason for every missing number', function () {
    $json = $this->withHeaders($this->headers)->getJson(route('tantra.api.results.show', '4'))->assertOk()->json();

    $a1 = collect($json['posts'])->firstWhere('key', 'A1');
    $a2 = collect($json['posts'])->firstWhere('key', 'A2');

    expect(array_keys($a1))->toContain('key', 'impressions', 'unique_impressions', 'clicks', 'reactions', 'comments', 'shares', 'engagement_rate', 'registrations', 'source_notes')
        ->and($a1['reactions'])->toBe(30)
        ->and($a1['impressions'])->toBeNull()
        ->and($a1['profile'])->toBe('adi')
        ->and(implode(' ', $a1['source_notes']))->toContain('not available for personal posts')
        ->and($a2['reactions'])->toBeNull()
        ->and(implode(' ', $a2['source_notes']))->toContain('not published yet')
        ->and($json['by_format']['carousel']['posts'])->toBe(2)
        ->and($json['by_format']['carousel']['reactions'])->toEqual(['total' => 30, 'per_post' => 30, 'posts_with_it' => 1])
        ->and($json['by_pillar'])->toHaveKeys(['proof', 'story']);
});

it('answers only for the caller\'s own account', function () {
    $other = createApiTestToken();

    $json = $this->withHeaders(['Authorization' => 'Bearer '.$other['plain_token']])->getJson(route('tantra.api.results.show', '4'))->assertOk()->json();
    expect($json['posts'])->toBe([]);
});
