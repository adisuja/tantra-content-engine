<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Support\PostPlatformMetaRules;
use App\Tantra\LinkedIn\LinkedInPoll;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * tantra: LinkedIn polls, video title, visibility, reshares and the 20 image cap.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->member = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'abc123xyz',
        'token_expires_at' => now()->addDays(60),
    ]);
    $this->page = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456',
        'token_expires_at' => now()->addDays(60),
        'meta' => ['organization_id' => '123456'],
    ]);
});

function linkedInFormatPost(SocialAccount $account, array $attributes): Post
{
    $post = Post::factory()->forAccount($account)->create(['content' => 'Tell us what you think', ...$attributes]);
    $post->setRelation('socialAccount', $account);

    return $post;
}

function fakeLinkedInPosts(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/rest/videos?action=initializeUpload') => Http::response(['value' => [
                'video' => 'urn:li:video:V1',
                'uploadToken' => 't',
                'uploadInstructions' => [['uploadUrl' => 'https://upload.linkedin.test/chunk', 'firstByte' => 0, 'lastByte' => 9]],
            ]]),
            $url === 'https://upload.linkedin.test/chunk' => Http::response(null, 200, ['etag' => 'e1']),
            str_contains($url, 'finalizeUpload') => Http::response(null, 200),
            str_contains($url, '/rest/videos/') => Http::response(['status' => 'AVAILABLE']),
            str_contains($url, '/rest/images?action=initializeUpload') => Http::response(['value' => [
                'uploadUrl' => 'https://upload.linkedin.test/image',
                'image' => 'urn:li:image:'.uniqid(),
            ]]),
            str_contains($url, '/rest/posts') => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:42']),
            default => Http::response(str_repeat('x', 10), 200),
        };
    });
}

function linkedInPostRequest(): Request
{
    return collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->first(fn (Request $request): bool => str_ends_with($request->url(), '/rest/posts'));
}

test('a linkedin profile poll is published as content.poll with the post text as commentary', function () {
    fakeLinkedInPosts();
    $post = linkedInFormatPost($this->member, [
        'content_type' => ContentType::LinkedInPoll,
        'meta' => ['linkedin_poll' => ['question' => 'Best day to post?', 'options' => ['Monday', ' ', 'Tuesday', 'Friday'], 'duration' => 'ONE_WEEK']],
    ]);

    $result = (new LinkedInPublisher)->publish($post);

    expect($result['id'])->toBe('urn:li:share:42')
        ->and(linkedInPostRequest()['commentary'])->toBe('Tell us what you think')
        ->and(linkedInPostRequest()['content'])->toBe(['poll' => [
            'question' => 'Best day to post?',
            'options' => [['text' => 'Monday'], ['text' => 'Tuesday'], ['text' => 'Friday']],
            'settings' => ['duration' => 'ONE_WEEK'],
        ]]);
});

test('a linkedin page poll defaults to three days and is always public', function () {
    fakeLinkedInPosts();
    $post = linkedInFormatPost($this->page, [
        'content_type' => ContentType::LinkedInPagePoll,
        'meta' => ['linkedin_poll' => ['question' => 'Pick one', 'options' => ['A', 'B']], 'visibility' => 'CONNECTIONS'],
    ]);

    (new LinkedInPagePublisher)->publish($post);

    expect(linkedInPostRequest()['author'])->toBe('urn:li:organization:123456')
        ->and(linkedInPostRequest()['visibility'])->toBe('PUBLIC')
        ->and(data_get(linkedInPostRequest()->data(), 'content.poll.settings.duration'))->toBe('THREE_DAYS');
});

test('a linkedin poll needs a question and two different choices before it is scheduled', function () {
    expect(PostPlatformMetaRules::requiredMetaViolation($this->member, ['linkedin_poll' => ['options' => ['A', 'B']]], ContentType::LinkedInPoll))
        ->toBe(['linkedin_poll.question', __('posts.form.formats.linkedin_poll_question_required')])
        ->and(PostPlatformMetaRules::requiredMetaViolation($this->member, ['linkedin_poll' => ['question' => 'Q', 'options' => ['A']]], ContentType::LinkedInPoll)[0])
        ->toBe('linkedin_poll.options')
        ->and(PostPlatformMetaRules::requiredMetaViolation($this->member, ['linkedin_poll' => ['question' => 'Q', 'options' => ['A', 'a']]], ContentType::LinkedInPoll)[0])
        ->toBe('linkedin_poll.options')
        ->and(PostPlatformMetaRules::requiredMetaViolation($this->page, ['linkedin_poll' => ['question' => 'Q', 'options' => ['A', 'B']]], ContentType::LinkedInPagePoll))
        ->toBeNull();
});

test('linkedin poll meta is validated: four choices of thirty characters and a known duration', function () {
    $rules = PostPlatformMetaRules::rules();

    expect(Validator::make(['meta' => ['linkedin_poll' => ['question' => 'Q', 'options' => ['A', 'B', 'C', 'D'], 'duration' => 'TWO_WEEKS']]], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['meta' => ['linkedin_poll' => ['options' => ['A', 'B', 'C', 'D', 'E']]]], $rules)->errors()->has('meta.linkedin_poll.options'))->toBeTrue()
        ->and(Validator::make(['meta' => ['linkedin_poll' => ['options' => [str_repeat('a', 31)]]]], $rules)->errors()->has('meta.linkedin_poll.options.0'))->toBeTrue()
        ->and(Validator::make(['meta' => ['linkedin_poll' => ['duration' => 'ONE_MONTH']]], $rules)->errors()->has('meta.linkedin_poll.duration'))->toBeTrue()
        ->and(Validator::make(['meta' => ['linkedin_poll' => ['question' => str_repeat('q', 141)]]], $rules)->errors()->has('meta.linkedin_poll.question'))->toBeTrue();
});

test('linkedin polls take no media and the publish config carries their limits', function () {
    expect(ContentType::LinkedInPoll->maxMediaCount())->toBe(0)
        ->and(ContentType::LinkedInPagePoll->requiresMedia())->toBeFalse()
        ->and(ContentType::LinkedInPoll->platform()->value)->toBe('linkedin')
        ->and(ContentType::LinkedInPagePoll->platform()->value)->toBe('linkedin-page')
        ->and($this->member->platform->publishConfig()['linkedinPoll'])->toBe(LinkedInPoll::limits());
});

test('a linkedin member post can go to connections only and turn off reshares', function () {
    fakeLinkedInPosts();
    $post = linkedInFormatPost($this->member, [
        'content_type' => ContentType::LinkedInPost,
        'meta' => ['visibility' => 'CONNECTIONS', 'disable_reshare' => true],
    ]);

    (new LinkedInPublisher)->publish($post);

    expect(linkedInPostRequest()['visibility'])->toBe('CONNECTIONS')
        ->and(linkedInPostRequest()['isReshareDisabledByAuthor'])->toBeTrue();
});

test('a linkedin post stays public with reshares on by default', function () {
    fakeLinkedInPosts();
    (new LinkedInPublisher)->publish(linkedInFormatPost($this->member, ['content_type' => ContentType::LinkedInPost]));

    expect(linkedInPostRequest()['visibility'])->toBe('PUBLIC')
        ->and(linkedInPostRequest()['isReshareDisabledByAuthor'])->toBeFalse();
});

test('a linkedin video post carries its title', function () {
    fakeLinkedInPosts();
    $post = linkedInFormatPost($this->member, [
        'content_type' => ContentType::LinkedInPost,
        'meta' => ['video_title' => 'Launch day recap'],
        'media' => [['id' => 'v1', 'path' => 'media/v.mp4', 'url' => 'https://example.com/v.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'v.mp4']],
    ]);

    (new LinkedInPublisher)->publish($post);

    expect(data_get(linkedInPostRequest()->data(), 'content.media'))->toBe(['id' => 'urn:li:video:V1', 'title' => 'Launch day recap']);
});

test('linkedin profile and page posts take up to twenty images', function () {
    fakeLinkedInPosts();
    $media = collect(range(1, 20))->map(fn (int $i): array => [
        'id' => "img{$i}", 'path' => "media/{$i}.png", 'url' => "https://example.com/{$i}.png", 'mime_type' => 'image/png', 'original_filename' => "{$i}.png",
    ])->all();

    (new LinkedInPagePublisher)->publish(linkedInFormatPost($this->page, ['content_type' => ContentType::LinkedInPagePost, 'media' => $media]));

    expect(data_get(linkedInPostRequest()->data(), 'content.multiImage.images'))->toHaveCount(20)
        ->and(ContentType::LinkedInPost->maxMediaCount())->toBe(20)
        ->and(ContentType::ZernioLinkedInPagePost->maxMediaCount())->toBe(20)
        ->and(ContentType::UnipileLinkedInPost->maxMediaCount())->toBe(10);
});
