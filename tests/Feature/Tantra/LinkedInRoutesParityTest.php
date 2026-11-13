<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Unipile\UnipilePublisher;
use App\Tantra\Zernio\ZernioPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * tantra: the Zernio page and Unipile profile routes honour the link preview
 * switch and the PDF document title like the native LinkedIn publisher.
 */
beforeEach(function () {
    config([
        'tantra.zernio.key' => 'test-key', 'tantra.zernio.url' => 'https://zernio.test/api/v1',
        'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k',
    ]);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

function routePost(SocialAccount $account, ContentType $type, array $attributes = []): Post
{
    $post = Post::factory()->forAccount($account)->create(['content' => 'Read it: https://example.com/guide', 'content_type' => $type, ...$attributes]);
    $post->setRelation('socialAccount', $account);

    return $post;
}

test('zernio sends disableLinkPreview only when the link preview is turned off', function (?bool $preview, bool $disabled) {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => 'z1', 'access_token' => 'zernio-managed']);
    Http::fake(['zernio.test/api/v1/posts' => Http::response(['post' => ['_id' => 'zp', 'status' => 'published', 'platforms' => [['status' => 'published']]]], 201)]);

    app(ZernioPublisher::class)->publish(routePost($channel, ContentType::ZernioLinkedInPagePost, ['meta' => $preview === null ? [] : ['link_preview' => $preview]]));

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/posts')
        && (data_get($r->data(), 'platforms.0.platformSpecificData.disableLinkPreview') === true) === $disabled);
})->with([
    'default' => [null, false],
    'on' => [true, false],
    'off' => [false, true],
]);

test('unipile sends the first link as external_link only when the link preview is turned on', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc', 'access_token' => 'unipile-managed']);
    Http::fake(['unipile.test/v2/acc/posts' => Http::response(['id' => '1'], 201)]);

    app(UnipilePublisher::class)->publish(routePost($channel, ContentType::UnipileLinkedInPost, ['meta' => ['link_preview' => true]]));

    Http::assertSent(fn (Request $r): bool => $r['external_link'] === 'https://example.com/guide' && ! isset($r['document_title']));
});

test('unipile sends no external_link by default or when the link preview is off', function (array $meta) {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc', 'access_token' => 'unipile-managed']);
    Http::fake(['unipile.test/v2/acc/posts' => Http::response(['id' => '1'], 201)]);

    app(UnipilePublisher::class)->publish(routePost($channel, ContentType::UnipileLinkedInPost, ['meta' => $meta]));

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/posts') && ! isset($r['external_link']) && ! isset($r['document_title']));
})->with(['default' => [[]], 'off' => [['link_preview' => false]]]);

test('unipile sends no document title when none was typed', function () {
    Storage::fake();
    Storage::put('media/deck.pdf', '%PDF-1.4');
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc', 'access_token' => 'unipile-managed']);
    Http::fake(['unipile.test/v2/acc/posts' => Http::response(['id' => '1'], 201)]);

    app(UnipilePublisher::class)->publish(routePost($channel, ContentType::UnipileLinkedInPost, [
        'media' => [['id' => 'd1', 'path' => 'media/deck.pdf', 'url' => 'https://example.com/deck.pdf', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf']],
    ]));

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/posts') && ! isset($r['document_title']));
});

test('unipile sends the document title cut to 58 characters on a PDF post', function () {
    Storage::fake();
    Storage::put('media/deck.pdf', '%PDF-1.4');
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc', 'access_token' => 'unipile-managed']);
    Http::fake(['unipile.test/v2/acc/posts' => Http::response(['id' => '1'], 201)]);

    app(UnipilePublisher::class)->publish(routePost($channel, ContentType::UnipileLinkedInPost, [
        'meta' => ['document_title' => str_repeat('Revenue playbook ', 6)],
        'media' => [['id' => 'd1', 'path' => 'media/deck.pdf', 'url' => 'https://example.com/deck.pdf', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf']],
    ]));

    Http::assertSent(fn (Request $r): bool => mb_strlen($r['document_title']) === UnipilePublisher::DOCUMENT_TITLE_MAX
        && str_starts_with($r['document_title'], 'Revenue playbook')
        && ! isset($r['external_link']));
});
