<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Zernio\ZernioPublisher;
use App\Tantra\Zernio\ZernioPublishException;
use App\Tantra\Zernio\ZernioVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['tantra.zernio.key' => 'test-key', 'tantra.zernio.url' => 'https://zernio.test/api/v1', 'tantra.zernio.webhook_secret' => 'whsec']);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id, 'name' => 'Tantra']);
    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::ZernioLinkedInPage,
        'platform_user_id' => '6af403938c39ae01b4bc3fce',
        'access_token' => 'zernio-managed',
        'meta' => ['provider' => 'zernio'],
    ]);
    $this->post = Post::factory()->forAccount($this->channel)->create([
        'user_id' => $this->user->id,
        'content' => 'Which funnel would you rather run?',
        'content_type' => ContentType::ZernioLinkedInPagePost,
        'meta' => ['thread_replies' => [['text' => 'Register here: https://example.com/?utm_content=TC3']]],
    ]);
    $this->post->setRelation('socialAccount', $this->channel);
});

it('publishes through Zernio once, with the first comment and a stable idempotency key', function () {
    Http::fake([
        'zernio.test/api/v1/posts' => Http::response(['post' => [
            '_id' => 'zp_1', 'status' => 'published',
            'platforms' => [['platform' => 'linkedin', 'status' => 'published', 'platformPostUrl' => 'https://www.linkedin.com/feed/update/urn:li:share:1']],
        ]], 201),
    ]);

    $result = app(ZernioPublisher::class)->publish($this->post);

    expect($result)->toBe(['id' => 'zp_1', 'url' => 'https://www.linkedin.com/feed/update/urn:li:share:1'])
        ->and($this->post->fresh()->meta['zernio_post_id'])->toBe('zp_1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://zernio.test/api/v1/posts'
        && $r->header('Idempotency-Key')[0] === 'tantra-'.$this->post->id
        && $r['publishNow'] === true
        && $r['platforms'][0]['accountId'] === '6af403938c39ae01b4bc3fce'
        && $r['platforms'][0]['platformSpecificData']['firstComment'] === 'Register here: https://example.com/?utm_content=TC3');
});

it('never creates a second Zernio post: a retry reads and retries the stored one', function () {
    $this->post->forceFill(['meta' => [...$this->post->meta, 'zernio_post_id' => 'zp_9']])->saveQuietly();

    Http::fake([
        'zernio.test/api/v1/posts/zp_9/retry' => Http::response(['post' => [
            '_id' => 'zp_9', 'status' => 'published',
            'platforms' => [['status' => 'published', 'platformPostUrl' => 'https://www.linkedin.com/feed/update/urn:li:share:9']],
        ]]),
        'zernio.test/api/v1/posts/zp_9' => Http::response(['post' => ['_id' => 'zp_9', 'status' => 'failed', 'platforms' => [['status' => 'failed', 'errorCategory' => 'platform_error']]]]),
    ]);

    expect(app(ZernioPublisher::class)->publish($this->post->fresh()))->toBe(['id' => 'zp_9', 'url' => 'https://www.linkedin.com/feed/update/urn:li:share:9']);

    Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://zernio.test/api/v1/posts');
});

it('waits and checks again while Zernio is still publishing', function () {
    Http::fake(['zernio.test/api/v1/posts' => Http::response(['post' => ['_id' => 'zp_2', 'status' => 'publishing', 'platforms' => [['status' => 'processing']]]], 201)]);

    expect(fn () => app(ZernioPublisher::class)->publish($this->post))
        ->toThrow(fn (PlatformUnavailableException $e) => expect($e->retryDelaySeconds)->toBe(60));
    expect($this->post->fresh()->meta['zernio_post_id'])->toBe('zp_2');
});

it('fails at once on a content refusal Zernio reports inside a 207', function () {
    Http::fake(['zernio.test/api/v1/posts' => Http::response(['post' => ['_id' => 'zp_3', 'status' => 'failed', 'platforms' => [[
        'status' => 'failed', 'errorCategory' => 'user_content', 'errorMessage' => 'Duplicate content',
    ]]]], 207)]);

    expect(fn () => app(ZernioPublisher::class)->publish($this->post))->toThrow(ZernioPublishException::class, 'Duplicate content');
});

it('reports a missing key on the channel instead of failing silently', function () {
    config(['tantra.zernio.key' => null]);

    expect(fn () => ZernioVerifier::verify($this->channel))->toThrow(TokenExpiredException::class, 'Zernio key missing');
});

it('verifies a connected Zernio account', function () {
    Http::fake(['zernio.test/api/v1/accounts*' => Http::response(['accounts' => [['_id' => '6af403938c39ae01b4bc3fce', 'platform' => 'linkedin']], 'statusCounts' => ['all' => 1, 'disconnected' => 0]])]);

    expect(ZernioVerifier::verify($this->channel))->toBeTrue();
});

it('records a signed Zernio webhook on the post and refuses an unsigned one', function () {
    $this->post->forceFill(['meta' => [...$this->post->meta, 'zernio_post_id' => 'zp_5']])->saveQuietly();
    $body = json_encode(['id' => 'evt_1', 'event' => 'post.published', 'post' => ['id' => 'zp_5', 'status' => 'published', 'platforms' => [['status' => 'published', 'publishedUrl' => 'https://www.linkedin.com/feed/update/urn:li:share:5']]]]);

    $this->call('POST', route('tantra.webhooks.zernio'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ZERNIO_SIGNATURE' => 'bad'], $body)->assertStatus(400);

    $this->call('POST', route('tantra.webhooks.zernio'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ZERNIO_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
        'HTTP_X_ZERNIO_EVENT_ID' => 'evt_1',
    ], $body)->assertOk();

    expect($this->post->fresh()->meta['zernio_event']['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:share:5');
});

it('links a Zernio page to a workspace, at most two', function () {
    Http::fake(['zernio.test/api/v1/accounts' => Http::response(['accounts' => [
        ['_id' => 'acc_page_2', 'platform' => 'linkedin', 'displayName' => 'GrowthClub'],
        ['_id' => 'acc_page_3', 'platform' => 'linkedin', 'displayName' => 'Third'],
    ]])]);

    $this->artisan('tantra:zernio-connect', ['workspace' => 'Tantra', 'account' => 'acc_page_2'])->assertSuccessful();
    expect(SocialAccount::query()->where('platform', Platform::ZernioLinkedInPage)->count())->toBe(2);

    $this->artisan('tantra:zernio-connect', ['workspace' => 'Tantra', 'account' => 'acc_page_3'])->assertFailed();
});

it('imports a Tantra page post as a Zernio page draft', function () {
    WorkspaceSettings::query()->create(['workspace_id' => $this->workspace->id, 'brand_profile_key' => 'tantra', 'review_owner_user_id' => $this->user->id]);

    ImportBatch::execute($this->user, ['engine_batch_id' => '5', 'posts' => [[
        'engine_post_id' => 'TP1', 'profile' => 'tantra', 'platform' => 'linkedin', 'date' => '2026-11-20', 'time' => '10:30',
        'tz' => 'America/New_York', 'text' => 'Page post.', 'first_comment' => 'Link here.',
    ]]]);

    $post = Post::query()->where('post_group_id', ReviewItem::query()->where('engine_post_id', 'TP1')->sole()->post_group_id)->sole();

    expect($post->social_account_id)->toBe($this->channel->id)
        ->and($post->content_type)->toBe(ContentType::ZernioLinkedInPagePost);
});
