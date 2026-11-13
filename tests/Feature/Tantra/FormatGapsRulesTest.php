<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use App\Support\ThreadReplies;
use App\Tantra\Composer\TantraMetaRules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * tantra: the new per-network settings are validated the same way on every
 * entry point, first comments are allowed where they publish, and the
 * composer's lookups answer.
 */
function gapRules(array $meta): Illuminate\Validation\Validator
{
    return Validator::make(['meta' => $meta], PostPlatformMetaRules::rules());
}

test('the new settings pass with valid values and fail with wrong ones', function (array $meta, ?string $error) {
    $validator = gapRules($meta);

    $error === null
        ? expect($validator->errors()->all())->toBe([])
        : expect($validator->errors()->has($error))->toBeTrue();
})->with([
    'reply control' => [['reply_control' => 'followers_only'], null],
    'bad reply control' => [['reply_control' => 'friends'], 'meta.reply_control'],
    'x reply settings' => [['reply_settings' => 'verified'], null],
    'bad x reply settings' => [['reply_settings' => 'everyone'], 'meta.reply_settings'],
    'x quote' => [['quote_url' => 'https://twitter.com/a/status/42'], null],
    'bluesky quote' => [['quote_url' => 'https://bsky.app/profile/a.bsky.social/post/3kq'], null],
    'bad quote' => [['quote_url' => 'https://example.com/post/1'], 'meta.quote_url'],
    'language' => [['language' => 'pt-BR'], null],
    'bad language' => [['language' => 'English'], 'meta.language'],
    'visibility' => [['visibility' => 'CONNECTIONS'], null],
    'mastodon visibility' => [['visibility' => 'direct'], null],
    'bad visibility' => [['visibility' => 'secret'], 'meta.visibility'],
    'self labels' => [['self_labels' => ['nudity']], null],
    'bad self label' => [['self_labels' => ['spoiler']], 'meta.self_labels.0'],
    'tags' => [['tags' => ['a', 'b']], null],
    'tags over 500' => [['tags' => array_map(fn (int $i): string => str_pad("tag{$i}", 20, 'x'), range(1, 30))], 'meta.tags'],
    'thumbnail' => [['thumbnail_url' => 'https://cdn.test/t.jpg'], null],
    'bad thumbnail' => [['thumbnail_url' => 'not a url'], 'meta.thumbnail_url'],
    'mastodon poll length' => [['mastodon_poll' => ['options' => ['a', 'b'], 'expires_in' => 60]], 'meta.mastodon_poll.expires_in'],
    'threads poll too many' => [['threads_poll' => ['options' => ['a', 'b', 'c', 'd', 'e']]], 'meta.threads_poll.options'],
    'telegram poll' => [['telegram_poll' => ['question' => 'Q', 'options' => ['a', 'b']]], null],
    'discord poll length' => [['discord_poll' => ['duration_hours' => 1000]], 'meta.discord_poll.duration_hours'],
    'location id' => [['location_id' => '123'], null],
    'bad location id' => [['location_id' => 'abc'], 'meta.location_id'],
    'booleans' => [['disable_reshare' => true, 'spoiler' => false, 'paid_partnership' => true, 'sensitive' => true, 'has_spoiler' => true, 'pin' => true, 'protect_content' => true, 'disable_notification' => true, 'upload_to_inbox' => true], null],
]);

test('a started poll needs two different choices, and a telegram or discord poll a question', function () {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::Threads, ['threads_poll' => ['options' => ['Yes']]])[0])->toBe('threads_poll.options')
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::Mastodon, ['mastodon_poll' => ['options' => ['A', 'a']]])[0])->toBe('mastodon_poll.options')
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::Telegram, ['telegram_poll' => ['options' => ['A', 'B']]])[0])->toBe('telegram_poll.question')
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::Threads, ['threads_poll' => ['options' => ['Yes', 'No']]]))->toBeNull()
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::Mastodon, ['mastodon_poll' => ['options' => ['', ' ']]]))->toBeNull();
});

test('meta is normalized: trimmed poll choices, YouTube tags without # or repeats', function () {
    expect(PostPlatformMetaRules::normalize(['threads_poll' => ['options' => [' Yes ', '', 'No']], 'tags' => ['#AI', 'ai', ' sales ']]))
        ->toBe(['threads_poll' => ['options' => ['Yes', 'No']], 'tags' => ['AI', 'sales']])
        ->and(TantraMetaRules::youtubeTagsLength(['ai sales', 'b']))->toBe(12)
        ->and(TantraMetaRules::xQuoteId('https://x.com/a/status/99?s=1'))->toBe('99')
        ->and(TantraMetaRules::blueskyQuote('https://bsky.app/profile/did:plc:abc/post/3kq'))->toBe(['did:plc:abc', '3kq']);
});

test('instagram, facebook and youtube take one first comment; threads chains like x', function () {
    foreach ([Platform::Instagram, Platform::InstagramFacebook, Platform::Facebook, Platform::YouTube] as $platform) {
        expect(ThreadReplies::replyContentType($platform))->toBe(ContentType::LinkedInComment)
            ->and(ThreadReplies::maxReplies($platform))->toBe(1);
    }

    expect(ThreadReplies::replyContentType(Platform::Threads))->toBe(ContentType::ThreadsPost)
        ->and(ThreadReplies::maxReplies(Platform::Threads))->toBe(ThreadReplies::MAX_REPLIES)
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::Instagram, ['thread_replies' => ['one', 'two']])[0])->toBe('thread_replies')
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::Facebook, ['thread_replies' => ['Link below']]))->toBeNull();
});

test('a youtube long-form video has no length cap and a 16:9 frame', function () {
    expect(ContentType::YouTubeVideo->maxVideoDurationSec())->toBeNull()
        ->and(ContentType::YouTubeVideo->aspectRatio())->toBe('16:9')
        ->and(ContentType::YouTubeVideo->platform())->toBe(Platform::YouTube)
        ->and(ContentType::YouTubeShort->maxVideoDurationSec())->toBe(180)
        ->and(ContentType::forPlatform(Platform::YouTube))->toContain(ContentType::YouTubeShort, ContentType::YouTubeVideo)
        ->and(ContentType::defaultFor(Platform::YouTube))->toBe(ContentType::YouTubeShort);
});

test('the composer lists a youtube channel playlists and a pinterest board sections', function () {
    $result = createApiTestToken();
    $user = $result['user'];
    $workspace = $result['workspace'];
    $youtube = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id, 'token_expires_at' => now()->addDay()]);
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id, 'token_expires_at' => now()->addDay()]);
    Http::fake([
        'https://www.googleapis.com/youtube/v3/playlists*' => Http::response(['items' => [['id' => 'PL1', 'snippet' => ['title' => 'Webinars']]]]),
        config('trypost.platforms.pinterest.api').'/boards/b1/sections*' => Http::response(['items' => [['id' => 's1', 'name' => 'Ideas']]]),
    ]);

    $this->actingAs($user)->getJson(route('tantra.formats.youtube.playlists', $youtube))
        ->assertOk()->assertExactJson(['playlists' => [['id' => 'PL1', 'title' => 'Webinars']]]);
    $this->actingAs($user)->getJson(route('tantra.formats.pinterest.sections', [$pinterest, 'b1']))
        ->assertOk()->assertExactJson(['sections' => [['id' => 's1', 'name' => 'Ideas']]]);
    $this->actingAs($user)->getJson(route('tantra.formats.youtube.playlists', $pinterest))->assertForbidden();
});
