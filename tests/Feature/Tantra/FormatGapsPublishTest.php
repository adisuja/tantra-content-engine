<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\TikTok\PrivacyLevel;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\Discord\DiscordPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\MastodonPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\ThreadsPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\XPublisher;
use App\Services\Social\YouTubePublisher;
use Google\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * tantra: per-network format settings reach the publish requests, and a first
 * comment that fails never fails the post.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

function gapAccount(string $state, array $attributes = []): SocialAccount
{
    return SocialAccount::factory()->{$state}()->create(['workspace_id' => test()->workspace->id, 'token_expires_at' => now()->addDays(30), ...$attributes]);
}

function gapPost(SocialAccount $account, ContentType $type, array $attributes = []): Post
{
    $post = Post::factory()->forAccount($account)->create(['user_id' => test()->user->id, 'content' => 'Hello there', 'content_type' => $type, ...$attributes]);
    $post->setRelation('socialAccount', $account);

    return $post;
}

function gapSent(string $needle): Request
{
    return collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r): bool => str_contains($r->url().'#', $needle)) // a trailing # matches the end of the URL
        ->last() ?? throw new RuntimeException("No request to {$needle}");
}

function gapImage(): array
{
    return [['id' => 'img1', 'path' => 'media/a.jpg', 'url' => 'https://cdn.test/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg']];
}

// Instagram

test('instagram posts the first comment under a feed post, with location', function () {
    $account = gapAccount('instagram', ['platform_user_id' => 'ig_1']);
    $graph = 'https://graph.instagram.com/v25.0';
    Http::fake([
        "{$graph}/ig_1/media" => Http::response(['id' => 'c1']),
        "{$graph}/c1*" => Http::response(['status_code' => 'FINISHED']),
        "{$graph}/ig_1/media_publish" => Http::response(['id' => 'm1']),
        "{$graph}/m1/comments" => Http::response(['id' => 'cm1']),
        "{$graph}/m1*" => Http::response(['permalink' => 'https://www.instagram.com/p/X/']),
    ]);

    $result = (new InstagramPublisher)->publish(gapPost($account, ContentType::InstagramFeed, [
        'media' => gapImage(),
        'meta' => ['thread_replies' => ['Link in bio'], 'location_id' => '1234567'],
    ]));

    expect($result['thread_reply_ids'])->toBe(['cm1'])
        ->and(gapSent('/m1/comments')['message'])->toBe('Link in bio')
        ->and(gapSent('/ig_1/media#')['location_id'])->toBe('1234567');
});

test('an instagram first comment that fails leaves the post published and flags it', function () {
    $account = gapAccount('instagram', ['platform_user_id' => 'ig_1']);
    $graph = 'https://graph.instagram.com/v25.0';
    Http::fake([
        "{$graph}/ig_1/media" => Http::response(['id' => 'c1']),
        "{$graph}/c1*" => Http::response(['status_code' => 'FINISHED']),
        "{$graph}/ig_1/media_publish" => Http::response(['id' => 'm1']),
        "{$graph}/m1/comments" => Http::response(['error' => ['message' => 'Missing permission']], 403),
        "{$graph}/m1*" => Http::response(['permalink' => 'https://www.instagram.com/p/X/']),
    ]);
    $post = gapPost($account, ContentType::InstagramFeed, ['media' => gapImage(), 'meta' => ['thread_replies' => ['Link in bio']]]);

    $result = (new InstagramPublisher)->publish($post);

    expect($result['id'])->toBe('m1')
        ->and($result)->not->toHaveKey('thread_reply_ids')
        ->and($post->fresh()->meta['first_comment_failed'])->toBeTrue();
});

test('an instagram reel sends its cover image and audio name', function () {
    $account = gapAccount('instagram', ['platform_user_id' => 'ig_1']);
    $graph = 'https://graph.instagram.com/v25.0';
    Http::fake([
        "{$graph}/ig_1/media" => Http::response(['id' => 'c1']),
        "{$graph}/c1*" => Http::response(['status_code' => 'FINISHED']),
        "{$graph}/ig_1/media_publish" => Http::response(['id' => 'm1']),
        "{$graph}/m1*" => Http::response(['permalink' => 'https://www.instagram.com/reel/X/']),
    ]);

    (new InstagramPublisher)->publish(gapPost($account, ContentType::InstagramReel, [
        'media' => [['id' => 'v1', 'path' => 'media/v.mp4', 'url' => 'https://cdn.test/v.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'v.mp4']],
        'meta' => ['cover_url' => 'https://cdn.test/cover.jpg', 'audio_name' => 'Launch theme'],
    ]));

    expect(gapSent('/ig_1/media#')['cover_url'])->toBe('https://cdn.test/cover.jpg')
        ->and(gapSent('/ig_1/media#')['audio_name'])->toBe('Launch theme');
});

// Facebook

test('facebook posts the first comment under a page post', function () {
    $account = gapAccount('facebook', ['platform_user_id' => 'page_1']);
    Http::fake([
        '*/page_1/feed' => Http::response(['id' => 'page_1_99']),
        '*/page_1_99/comments' => Http::response(['id' => 'fc1']),
    ]);

    $result = (new FacebookPublisher)->publish(gapPost($account, ContentType::FacebookPost, ['meta' => ['thread_replies' => ['Sign up below']]]));

    expect($result['thread_reply_ids'])->toBe(['fc1'])
        ->and(gapSent('/page_1_99/comments')['message'])->toBe('Sign up below');
});

test('a facebook video carries its title', function () {
    $account = gapAccount('facebook', ['platform_user_id' => 'page_1']);
    Http::fake(['*/page_1/videos' => Http::response(['id' => 'vid_1'])]);

    (new FacebookPublisher)->publish(gapPost($account, ContentType::FacebookPost, [
        'media' => [['id' => 'v1', 'path' => 'media/v.mp4', 'url' => 'https://cdn.test/v.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'v.mp4']],
        'meta' => ['video_title' => 'Demo day'],
    ]));

    expect(gapSent('/page_1/videos')['title'])->toBe('Demo day');
});

// Threads

test('threads chains replies with reply_to_id and sends reply control, poll, link card and AI label', function () {
    $account = gapAccount('threads', ['platform_user_id' => 'u1']);
    $base = 'https://graph.threads.net/v1.0';
    $containers = 0;
    $published = 0;
    Http::fake([
        "{$base}/u1/threads" => function () use (&$containers) {
            $containers++;

            return Http::response(['id' => "c{$containers}"]);
        },
        "{$base}/c*" => Http::response(['status' => 'FINISHED']),
        "{$base}/u1/threads_publish" => function () use (&$published) {
            $published++;

            return Http::response(['id' => "p{$published}"]);
        },
        "{$base}/p*" => Http::response(['permalink' => 'https://www.threads.net/@u/post/X']),
    ]);

    $result = (new ThreadsPublisher)->publish(gapPost($account, ContentType::ThreadsPost, ['meta' => [
        'thread_replies' => ['Second', 'Third'],
        'reply_control' => 'followers_only',
        'threads_poll' => ['options' => ['Yes', 'No']],
        'link_attachment' => 'https://example.com',
        'is_ai_generated' => true,
    ]]));

    $creates = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $r): bool => str_ends_with($r->url(), '/u1/threads'))->values();

    expect($result['thread_reply_ids'])->toBe(['p2', 'p3'])
        ->and($creates[0]['reply_control'])->toBe('followers_only')
        ->and(json_decode($creates[0]['poll_attachment'], true))->toBe(['option_a' => 'Yes', 'option_b' => 'No'])
        ->and($creates[0]['link_attachment'])->toBe('https://example.com')
        ->and($creates[0]['is_made_with_ai'])->toBe('true')
        ->and($creates[1]['reply_to_id'])->toBe('p1')
        ->and($creates[1]['text'])->toBe('Second')
        ->and($creates[2]['reply_to_id'])->toBe('p2')
        ->and(isset($creates[1]['reply_control']))->toBeFalse();
});

test('a threads media post can be a spoiler and takes no poll or link card', function () {
    $account = gapAccount('threads', ['platform_user_id' => 'u1']);
    $base = 'https://graph.threads.net/v1.0';
    Http::fake([
        "{$base}/u1/threads" => Http::response(['id' => 'c1']),
        "{$base}/c1*" => Http::response(['status' => 'FINISHED']),
        "{$base}/u1/threads_publish" => Http::response(['id' => 'p1']),
        "{$base}/p1*" => Http::response(['permalink' => 'https://www.threads.net/@u/post/X']),
    ]);

    (new ThreadsPublisher)->publish(gapPost($account, ContentType::ThreadsPost, ['media' => gapImage(), 'meta' => [
        'spoiler' => true, 'threads_poll' => ['options' => ['Yes', 'No']], 'link_attachment' => 'https://example.com',
    ]]));

    $create = gapSent('/u1/threads#');
    expect($create['is_spoiler_media'])->toBe('true')
        ->and(isset($create['poll_attachment']))->toBeFalse()
        ->and(isset($create['link_attachment']))->toBeFalse();
});

// YouTube

function fakeGapYouTube(array $extra = []): void
{
    Http::fake([
        ...$extra,
        'https://cdn.test/video.mp4' => Http::response(str_repeat('x', 2048)),
        'https://youtube.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.example.test/session']),
        'https://upload.example.test/session' => Http::response(['id' => 'vid123']),
    ]);
    $client = new Client;
    $client->setHttpClient(Http::buildClient());
    test()->instance(Client::class, $client);
}

test('a youtube long-form video links to watch, carries its tags, joins the playlist, sets the thumbnail and posts the first comment', function () {
    $account = gapAccount('youtube');
    fakeGapYouTube([
        'https://www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['id' => 'pi1']),
        'https://cdn.test/thumb.jpg' => Http::response('jpegbytes'),
        'https://www.googleapis.com/upload/youtube/v3/thumbnails/set*' => Http::response(['items' => []]),
        'https://www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['id' => 'ct1']),
    ]);

    $result = (new YouTubePublisher)->publish(gapPost($account, ContentType::YouTubeVideo, [
        'content' => 'Full walkthrough',
        'media' => [['id' => 'v1', 'type' => 'video', 'path' => 'media/video.mp4', 'url' => 'https://cdn.test/video.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'video.mp4']],
        'meta' => ['tags' => ['revops', 'ai sales'], 'playlist_id' => 'PL1', 'thumbnail_url' => 'https://cdn.test/thumb.jpg', 'thread_replies' => ['Chapters below']],
    ]));

    expect($result['url'])->toBe('https://www.youtube.com/watch?v=vid123')
        ->and($result['thread_reply_ids'])->toBe(['ct1'])
        ->and(data_get(gapSent('youtube/v3/videos')->data(), 'snippet.tags'))->toBe(['revops', 'ai sales'])
        ->and(data_get(gapSent('playlistItems')->data(), 'snippet'))->toBe(['playlistId' => 'PL1', 'resourceId' => ['kind' => 'youtube#video', 'videoId' => 'vid123']])
        ->and(gapSent('thumbnails/set')->url())->toContain('videoId=vid123')
        ->and(data_get(gapSent('commentThreads')->data(), 'snippet.topLevelComment.snippet.textOriginal'))->toBe('Chapters below');
});

test('a youtube short keeps its shorts link, and a refused playlist or comment never fails it', function () {
    $account = gapAccount('youtube');
    fakeGapYouTube([
        'https://www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['error' => 'forbidden'], 403),
        'https://www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['error' => 'forbidden'], 403),
    ]);
    $post = gapPost($account, ContentType::YouTubeShort, [
        'content' => 'Quick tip',
        'media' => [['id' => 'v1', 'type' => 'video', 'path' => 'media/video.mp4', 'url' => 'https://cdn.test/video.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'video.mp4']],
        'meta' => ['playlist_id' => 'PL1', 'thread_replies' => ['More soon']],
    ]);

    $result = (new YouTubePublisher)->publish($post);

    expect($result)->toBe(['id' => 'vid123', 'url' => 'https://www.youtube.com/shorts/vid123'])
        ->and($post->fresh()->meta['youtube_followup_failed'])->toBe('playlist')
        ->and($post->fresh()->meta['first_comment_failed'])->toBeTrue();
});

// X

test('x sends reply settings, the quoted post id and paid partnership', function () {
    $account = gapAccount('x', ['username' => 'tantra']);
    Http::fake([config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '555']], 201)]);

    (new XPublisher)->publish(gapPost($account, ContentType::XPost, ['meta' => [
        'reply_settings' => 'mentionedUsers',
        'quote_url' => 'https://x.com/someone/status/1234567890?s=20',
        'paid_partnership' => true,
    ]]));

    $tweet = gapSent('/tweets');
    expect($tweet['reply_settings'])->toBe('mentionedUsers')
        ->and($tweet['quote_tweet_id'])->toBe('1234567890')
        ->and($tweet['paid_partnership'])->toBeTrue();
});

// Mastodon

test('mastodon sends visibility, language, sensitive and a poll on a text post', function () {
    $account = gapAccount('mastodon');
    Http::fake(['https://mastodon.social/api/v1/statuses' => Http::response(['id' => '9', 'url' => 'https://mastodon.social/@a/9'])]);

    (new MastodonPublisher)->publish(gapPost($account, ContentType::MastodonPost, ['meta' => [
        'visibility' => 'unlisted', 'language' => 'en', 'sensitive' => true,
        'mastodon_poll' => ['options' => ['A', 'B', ' '], 'expires_in' => 3600, 'multiple' => true],
    ]]));

    $status = gapSent('/statuses');
    expect($status['visibility'])->toBe('unlisted')
        ->and($status['language'])->toBe('en')
        ->and($status['sensitive'])->toBeTrue()
        ->and($status['poll'])->toBe(['options' => ['A', 'B'], 'expires_in' => 3600, 'multiple' => true]);
});

// Bluesky

test('bluesky sends langs, self-labels, a quote embed and a threadgate', function () {
    $account = gapAccount('bluesky', ['platform_user_id' => 'did:plc:me', 'username' => 'me.bsky.social']);
    $records = 0;
    Http::fake([
        '*/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:them']),
        '*/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => [['uri' => 'at://did:plc:them/app.bsky.feed.post/3kq', 'cid' => 'bafyq']]]),
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => function () use (&$records) {
            $records++;

            return Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/3new', 'cid' => 'bafy1']);
        },
    ]);

    (new BlueskyPublisher)->publish(gapPost($account, ContentType::BlueskyPost, ['meta' => [
        'language' => 'en',
        'self_labels' => ['graphic-media'],
        'quote_url' => 'https://bsky.app/profile/them.bsky.social/post/3kq',
        'reply_control' => 'mentioned_only',
    ]]));

    $creates = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $r): bool => str_contains($r->url(), 'createRecord'))->values();
    $post = $creates[0]['record'];

    expect($records)->toBe(2)
        ->and($post['langs'])->toBe(['en'])
        ->and($post['labels'])->toBe(['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'graphic-media']]])
        ->and($post['embed'])->toBe(['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://did:plc:them/app.bsky.feed.post/3kq', 'cid' => 'bafyq']])
        ->and($creates[1]['collection'])->toBe('app.bsky.feed.threadgate')
        ->and($creates[1]['rkey'])->toBe('3new')
        ->and($creates[1]['record']['allow'])->toBe([['$type' => 'app.bsky.feed.threadgate#mentionRule']]);
});

// Telegram

test('telegram sends silently and protected, then the poll, then pins the post', function () {
    $account = gapAccount('telegram');
    Http::fake([
        '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 42]]),
        '*/sendPoll' => Http::response(['ok' => true, 'result' => ['message_id' => 43]]),
        '*/pinChatMessage' => Http::response(['ok' => true, 'result' => true]),
    ]);

    (new TelegramPublisher)->publish(gapPost($account, ContentType::TelegramPost, ['meta' => [
        'disable_notification' => true, 'protect_content' => true, 'pin' => true,
        'telegram_poll' => ['question' => 'Which day?', 'options' => ['Mon', 'Tue']],
    ]]));

    expect(gapSent('/sendMessage')['disable_notification'])->toBeTrue()
        ->and(gapSent('/sendMessage')['protect_content'])->toBeTrue()
        ->and(gapSent('/sendPoll')['question'])->toBe('Which day?')
        ->and(json_decode(gapSent('/sendPoll')['options'], true))->toBe([['text' => 'Mon'], ['text' => 'Tue']])
        ->and(gapSent('/pinChatMessage')['message_id'])->toBe(42);
});

test('telegram hides a photo behind a spoiler', function () {
    $account = gapAccount('telegram');
    Http::fake(['*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

    (new TelegramPublisher)->publish(gapPost($account, ContentType::TelegramPost, ['media' => gapImage(), 'meta' => ['has_spoiler' => true]]));

    expect(gapSent('/sendPhoto')['has_spoiler'])->toBeTrue();
});

// Discord

test('discord sends a native poll with the message', function () {
    Cache::flush();
    config(['trypost.platforms.discord.bot_token' => 'BOT', 'services.discord.client_id' => '999']);
    $account = gapAccount('discord', ['platform_user_id' => '111']);
    $api = config('trypost.platforms.discord.api');
    Http::fake([
        "{$api}/guilds/*/channels" => Http::response([['id' => '444', 'name' => 'general', 'type' => 0]]),
        "{$api}/guilds/*/roles" => Http::response([['id' => '111', 'name' => '@everyone', 'permissions' => '3072']]),
        "{$api}/guilds/*/members/*" => Http::response(['roles' => []]),
        "{$api}/channels/*/messages" => Http::response(['id' => '777']),
    ]);

    (new DiscordPublisher)->publish(gapPost($account, ContentType::DiscordMessage, ['meta' => [
        'channel_id' => '444',
        'discord_poll' => ['question' => 'Lunch?', 'options' => ['Pizza', 'Tacos'], 'duration_hours' => 4],
    ]]));

    expect(data_get(gapSent('/messages')->data(), 'poll'))->toBe([
        'question' => ['text' => 'Lunch?'],
        'answers' => [['poll_media' => ['text' => 'Pizza']], ['poll_media' => ['text' => 'Tacos']]],
        'duration' => 4,
        'allow_multiselect' => false,
    ]);
});

// TikTok

test('a tiktok photo post carries its title and can go to the inbox as a draft', function () {
    Sleep::fake(syncWithCarbon: true);
    $account = gapAccount('tiktok', ['username' => 'tt']);
    $api = config('trypost.platforms.tiktok.api');
    Http::fake([
        "{$api}/post/publish/content/init/" => Http::response(['data' => ['publish_id' => 'pub1']]),
        "{$api}/post/publish/status/fetch/" => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX']]),
    ]);

    $result = (new TikTokPublisher)->publish(gapPost($account, ContentType::TikTokPhoto, [
        'media' => [['id' => 'i1', 'path' => 'media/i.jpg', 'url' => 'https://cdn.test/i.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'i.jpg', 'meta' => ['width' => 1080, 'height' => 1080]]],
        'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value, 'title' => 'Five tips', 'upload_to_inbox' => true],
    ]));

    expect($result['id'])->toBe('pub1')
        ->and(gapSent('/content/init/')['post_mode'])->toBe('MEDIA_UPLOAD')
        ->and(data_get(gapSent('/content/init/')->data(), 'post_info.title'))->toBe('Five tips');
});

// Pinterest

test('a pin goes into the chosen board section', function () {
    $account = gapAccount('pinterest');
    Http::fake([
        'https://cdn.test/*' => Http::response('jpegbytes', 200, ['Content-Type' => 'image/jpeg']),
        config('trypost.platforms.pinterest.api').'/pins' => Http::response(['id' => 'pin1'], 201),
    ]);

    (new PinterestPublisher)->publish(gapPost($account, ContentType::PinterestPin, ['media' => gapImage(), 'meta' => ['board_id' => 'b1', 'board_section_id' => 's9']]));

    expect(gapSent('/pins')['board_section_id'])->toBe('s9');
});
