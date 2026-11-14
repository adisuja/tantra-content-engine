<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Tantra\Social\MetaSignedRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/** tantra: what the Threads app needs for Meta App Review: the uninstall and delete callbacks and the Replies panel. */
beforeEach(function () {
    config([
        'services.threads.client_secret' => 'threads-secret',
        'services.facebook.client_secret' => 'meta-secret',
        'trypost.platforms.threads.graph_api' => 'https://graph.threads.net/v1.0',
        'tantra.meta.engagement_networks' => ['threads'],
    ]);
    Sleep::fake();
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '17841400000000001',
        'scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights', 'threads_read_replies', 'threads_manage_replies'],
    ]);
});

function threadsSigned(array $payload, string $secret = 'threads-secret'): string
{
    return MetaSignedRequest::sign(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), ...$payload], $secret);
}

/** A signed request built by hand, the way Meta documents it, not with the code under test. */
function metaDocumentedSignedRequest(array $payload, string $secret): string
{
    $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $encoded = $b64(json_encode($payload));

    return $b64(hash_hmac('sha256', $encoded, $secret, true)).'.'.$encoded;
}

/** A user id past PHP_INT_MAX, as raw JSON, must come back as the exact digits. */
function bigUserIdSignedRequest(string $secret): string
{
    $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $encoded = $b64('{"algorithm":"HMAC-SHA256","issued_at":'.time().',"user_id":17841400000000001234}');

    return $b64(hash_hmac('sha256', $encoded, $secret, true)).'.'.$encoded;
}

function fakeThreadsReplies(array $extra = []): void
{
    Http::fake([
        ...$extra,
        'graph.threads.net/v1.0/17841400000000001/threads?*' => Http::response(['data' => [
            ['id' => '111', 'text' => 'Hello', 'permalink' => 'https://www.threads.com/@a/post/1', 'timestamp' => '2026-11-15T10:00:00+0000', 'media_type' => 'TEXT_POST'],
            ['id' => '112', 'media_type' => 'REPOST_FACADE'],
            ['id' => '113', 'text' => 'Second', 'media_type' => 'TEXT_POST'],
        ]]),
        'graph.threads.net/v1.0/111/replies*' => Http::response(['data' => [
            ['id' => '222', 'text' => 'Nice', 'username' => 'ben', 'timestamp' => '2026-11-15T11:00:00+0000'],
            ['id' => '333', 'text' => 'spam', 'username' => 'bot', 'hide_status' => 'HIDDEN'],
        ]]),
        'graph.threads.net/v1.0/113/replies*' => Http::response(['error' => ['message' => 'boom', 'code' => 1]], 500),
    ]);
}

test('a signed request is accepted only with a known secret, fresh and well formed', function () {
    $documented = metaDocumentedSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '42'], 'threads-secret');

    expect(MetaSignedRequest::parse($documented, ['threads-secret']))->toHaveKey('user_id', '42')
        ->and(MetaSignedRequest::parse(threadsSigned(['user_id' => '42'], 'meta-secret'), ['threads-secret', 'meta-secret']))->toHaveKey('user_id', '42')
        ->and(MetaSignedRequest::parse($documented, ['other-secret']))->toBeNull()
        ->and(MetaSignedRequest::parse($documented, [null, '']))->toBeNull()
        ->and(MetaSignedRequest::parse('garbage', 'threads-secret'))->toBeNull()
        ->and(MetaSignedRequest::parse(['x'], 'threads-secret'))->toBeNull()
        ->and(MetaSignedRequest::parse(threadsSigned(['user_id' => '42', 'issued_at' => time() - 3 * 86400]), 'threads-secret'))->toBeNull()
        ->and(MetaSignedRequest::parse(bigUserIdSignedRequest('threads-secret'), 'threads-secret')['user_id'] ?? null)->toBe('17841400000000001234');
});

test('uninstall disconnects the channel and drops its tokens', function () {
    $this->post(route('tantra.threads.uninstall'), ['signed_request' => threadsSigned(['user_id' => '17841400000000001'])])
        ->assertOk()->assertJson(['success' => true]);

    $this->account->refresh();
    expect($this->account->status->value)->toBe('disconnected')
        ->and($this->account->access_token)->toBe('');
});

test('uninstall refuses a forged, empty or malformed request', function () {
    $this->post(route('tantra.threads.uninstall'), ['signed_request' => threadsSigned(['user_id' => '17841400000000001'], 'wrong')])->assertStatus(400);
    $this->post(route('tantra.threads.uninstall'), ['signed_request' => ['x']])->assertStatus(400);
    $this->post(route('tantra.threads.uninstall'))->assertStatus(400);

    config(['services.threads.client_secret' => '', 'services.facebook.client_secret' => null]);
    $this->post(route('tantra.threads.uninstall'), ['signed_request' => threadsSigned(['user_id' => '17841400000000001'], '')])->assertStatus(400);

    expect($this->account->fresh()->status->value)->toBe('connected');
});

test('delete removes every channel of that Threads user, its posts and analytics, and answers a status url and code', function () {
    $other = createApiTestToken()['workspace'];
    $second = SocialAccount::factory()->threads()->create(['workspace_id' => $other->id, 'platform_user_id' => '17841400000000001']);
    $stranger = SocialAccount::factory()->threads()->create(['workspace_id' => $other->id, 'platform_user_id' => '999']);
    DB::table('analytics_sync_states')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'network' => Platform::Threads->network(),
        'platform_user_id' => '17841400000000001', 'identity_key' => 'k1', 'collector' => 'publications', 'status' => 'idle',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->post(route('tantra.threads.delete'), ['signed_request' => threadsSigned(['user_id' => '17841400000000001'])])
        ->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

    expect(SocialAccount::query()->whereKey([$this->account->id, $second->id])->exists())->toBeFalse()
        ->and(SocialAccount::query()->whereKey($stranger->id)->exists())->toBeTrue()
        ->and(DB::table('analytics_sync_states')->where('platform_user_id', '17841400000000001')->exists())->toBeFalse();

    $this->get($response->json('url'))->assertOk()->assertSee($response->json('confirmation_code'))->assertSee('Your Threads data deletion request');
});

test('the deletion status page needs its signature', function () {
    $this->get('/accounts/threads/deletion/ABCDEFGH1234?at=1')->assertForbidden();
});

test('the replies panel lists replies to recent posts, skips reposts and survives one failing post', function () {
    fakeThreadsReplies();

    $this->actingAs($this->user)->getJson(route('tantra.threads.replies.index', $this->account))
        ->assertOk()
        ->assertJsonCount(2, 'posts')
        ->assertJsonPath('posts.0.id', '111')
        ->assertJsonCount(1, 'posts.0.replies')
        ->assertJsonPath('posts.0.replies.0.username', 'ben')
        ->assertJsonPath('posts.1.id', '113')
        ->assertJsonPath('posts.1.replies_failed', true);
});

test('a reply waits for its container, then posts under the reply it answers', function () {
    fakeThreadsReplies([
        'graph.threads.net/v1.0/17841400000000001/threads_publish' => Http::response(['id' => '999']),
        'graph.threads.net/v1.0/17841400000000001/threads' => Http::response(['id' => 'container-1']),
        'graph.threads.net/v1.0/container-1*' => Http::sequence()->push(['status' => 'IN_PROGRESS'])->push(['status' => 'FINISHED']),
        'graph.threads.net/v1.0/999*' => Http::response(['permalink' => 'https://www.threads.com/@a/post/9']),
    ]);

    $this->actingAs($this->user)->postJson(route('tantra.threads.replies.store', $this->account), ['reply_to_id' => '222', 'text' => 'Thanks Ben'])
        ->assertOk()->assertJson(['id' => '999', 'permalink' => 'https://www.threads.com/@a/post/9']);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/17841400000000001/threads')
        && $request['reply_to_id'] === '222' && $request['text'] === 'Thanks Ben' && $request['media_type'] === 'TEXT');
    Sleep::assertSleptTimes(1);
});

test('a reply to something outside the panel is refused', function () {
    fakeThreadsReplies();

    $this->actingAs($this->user)->postJson(route('tantra.threads.replies.store', $this->account), ['reply_to_id' => '55555', 'text' => 'Hi'])
        ->assertStatus(422);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/threads_publish'));
});

test('a channel without the reply scopes, or disconnected, gets a reconnect link', function () {
    $this->account->update(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights']]);

    $this->actingAs($this->user)->getJson(route('tantra.threads.replies.index', $this->account))
        ->assertOk()->assertJson(['needs_reconnect' => true])
        ->assertJsonPath('reconnect_url', fn (string $url) => str_contains($url, '/connect/threads') && str_contains($url, 'reconnect='.$this->account->id));
    $this->actingAs($this->user)->postJson(route('tantra.threads.replies.store', $this->account), ['reply_to_id' => '222', 'text' => 'Hi'])
        ->assertStatus(409);

    $this->account->update(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    $this->account->markAsDisconnected('gone');
    $this->actingAs($this->user)->getJson(route('tantra.threads.replies.index', $this->account))->assertJson(['needs_reconnect' => true]);
});

test('a token Threads refuses turns into a reconnect link', function () {
    Http::fake(['graph.threads.net/*' => Http::response(['error' => ['message' => 'expired', 'code' => 190]], 400)]);

    $this->actingAs($this->user)->getJson(route('tantra.threads.replies.index', $this->account))
        ->assertOk()->assertJson(['needs_reconnect' => true]);
});

test('another workspace cannot read or answer the replies', function () {
    $other = createApiTestToken()['user'];

    $this->actingAs($other)->getJson(route('tantra.threads.replies.index', $this->account))->assertForbidden();
    $this->actingAs($other)->postJson(route('tantra.threads.replies.store', $this->account), ['reply_to_id' => '222', 'text' => 'Hi'])->assertForbidden();
});
