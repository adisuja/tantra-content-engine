<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's "Delete from <network>" on a published post (D33, docs/crm/engine-patches/d33-delete-post/README.md).
// Nothing reaches a network: every provider call is faked.

beforeEach(function () {
    Notification::fake();
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    $this->direct = workspaceMember($this->team, 'member', ['email' => 'dana@example.test']);
    $this->peer = workspaceMember($this->team, 'member', ['email' => 'pat@example.test']);
    $this->admin = workspaceMember($this->team, 'admin', ['email' => 'amy@example.test']);
    $stranger = User::factory()->create(['email' => 'stan@example.test']);
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);

    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adi Murali', 'access_token' => 'tok-secret-abc', 'status' => Status::Connected]);
    $this->page = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedInPage, 'display_name' => 'Tantra page', 'status' => Status::Connected]);
    $this->unipile = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_77', 'display_name' => 'Scott', 'status' => Status::Connected]);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::X, 'display_name' => 'Tantra on X', 'status' => Status::Connected]);
    $this->bluesky = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::Bluesky, 'platform_user_id' => 'did:plc:abc123', 'meta' => ['service' => 'https://bsky.test'], 'display_name' => 'Tantra Bluesky', 'status' => Status::Connected]);
    $this->threads = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::Threads, 'display_name' => 'Tantra Threads', 'status' => Status::Connected]);
    $this->facebook = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::Facebook, 'display_name' => 'Tantra Facebook', 'status' => Status::Connected]);
    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::Instagram, 'display_name' => 'Tantra Instagram', 'status' => Status::Connected]);
    $this->tiktok = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::TikTok, 'display_name' => 'Tantra TikTok', 'status' => Status::Connected]);
    $this->theirs = SocialAccount::factory()->create(['workspace_id' => $this->other->id, 'platform' => Platform::LinkedIn, 'status' => Status::Connected]);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id},ws_other={$this->other->id}",
        'tantra.unipile.key' => 'unipile-key',
        'tantra.unipile.dsn' => 'https://unipile.test/api/v2',
    ]);
});

function crmDel(object $test, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))
        ->postJson('/api/tantra/engagement/publish/delete', ['workspace' => 'ws_team', 'actor_email' => 'dana@example.test', ...$body]);
}

function crmDelPosts(object $test): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer crm-key'])
        ->postJson('/api/tantra/engagement/posts', ['workspace' => 'ws_team', 'statuses' => ['published', 'publishing', 'scheduled', 'draft', 'pending_approval', 'failed']]);
}

function crmDelPost(object $test, SocialAccount $account, string $remoteId, array $attributes = []): Post
{
    return Post::factory()->forAccount($account)->published()->create(['user_id' => $test->direct->id, 'platform_post_id' => $remoteId, 'content' => 'We just opened the December webinar.', ...$attributes]);
}

function crmDelRow(Post $post): ?Post
{
    return Post::query()->withoutGlobalScopes()->find($post->id);
}

function crmDelUntouched(Post $post): void
{
    $fresh = crmDelRow($post);
    expect($fresh->deleted_at)->toBeNull()
        ->and($fresh->deleted_by)->toBeNull()
        ->and($fresh->status)->toBe($post->status)
        ->and($fresh->publish_status)->toBe($post->publish_status)
        ->and(Post::query()->find($post->id))->not->toBeNull();
}

function crmDelSentOnce(string $method, string $startsWith): void
{
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => $r->method() === $method && str_starts_with($r->url(), $startsWith));
}

it('answers 401 without the key and with a wrong key', function () {
    Http::fake();
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:1');
    crmDel($this, ['post_id' => $post->id], null)->assertUnauthorized();
    crmDel($this, ['post_id' => $post->id], 'nope')->assertUnauthorized();
    Http::assertNothingSent();
    crmDelUntouched($post);
});

it('deletes a published LinkedIn post: one DELETE for the stored id, then the post is soft deleted and hidden everywhere', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7001');

    $r = crmDel($this, ['post_id' => $post->id])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => ['deleted' => true, 'platform' => 'linkedin']]);

    crmDelSentOnce('DELETE', rtrim((string) config('trypost.platforms.linkedin.api'), '/').'/rest/posts/urn%3Ali%3Ashare%3A7001');
    Http::assertSent(fn (Request $req): bool => $req->hasHeader('X-RestLi-Method', 'DELETE') && $req->hasHeader('LinkedIn-Version') && $req->hasHeader('Authorization', 'Bearer tok-secret-abc'));
    $row = crmDelRow($post);
    expect($row->deleted_at)->not->toBeNull()
        ->and($row->deleted_by)->toBe($this->direct->id)
        ->and($row->publish_status)->toBe(PublishStatus::Deleted)
        ->and($row->platform_post_id)->toBe('urn:li:share:7001')
        ->and(Post::query()->find($post->id))->toBeNull();
    expect(collect(crmDelPosts($this)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($post->id);
    expect($r->getContent())->not->toContain('tok-secret-abc');
});

it('deletes a LinkedIn page post with the same call on the page API', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $post = crmDelPost($this, $this->page, 'urn:li:share:7002');
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.platform', 'linkedin');
    crmDelSentOnce('DELETE', rtrim((string) config('trypost.platforms.linkedin-page.api'), '/').'/rest/posts/urn%3Ali%3Ashare%3A7002');
});

it('deletes a LinkedIn post through Unipile with the Unipile account and post id', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $post = crmDelPost($this, $this->unipile, '7123456789', ['meta' => ['unipile_post_id' => '7123456789']]);
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.platform', 'linkedin');
    crmDelSentOnce('DELETE', 'https://unipile.test/api/v2/acc_77/posts/7123456789');
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-API-KEY', 'unipile-key'));
    expect(Post::query()->find($post->id))->toBeNull();
});

it('deletes an X post', function () {
    Http::fake(['*' => Http::response(['data' => ['deleted' => true]], 200)]);
    $post = crmDelPost($this, $this->x, '1850000000000000001');
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.platform', 'x');
    crmDelSentOnce('DELETE', rtrim((string) config('trypost.platforms.x.api'), '/').'/tweets/1850000000000000001');
    expect(Post::query()->find($post->id))->toBeNull();
});

it('does not take an X answer that says it did not delete for a success', function () {
    Http::fake(['*' => Http::response(['data' => ['deleted' => false]], 200)]);
    $post = crmDelPost($this, $this->x, '1850000000000000002');
    crmDel($this, ['post_id' => $post->id])->assertStatus(422)->assertJsonPath('error', 'delete_not_supported');
    crmDelUntouched($post);
});

it('deletes a Bluesky post with the record key, whether the stored id is the key or the at-uri', function () {
    Http::fake(['*' => Http::response(['commit' => []], 200)]);
    $byKey = crmDelPost($this, $this->bluesky, '3kabcdef');
    $byUri = crmDelPost($this, $this->bluesky, 'at://did:plc:abc123/app.bsky.feed.post/3kghijkl');
    crmDel($this, ['post_id' => $byKey->id])->assertOk()->assertJsonPath('data.platform', 'bluesky');
    crmDel($this, ['post_id' => $byUri->id])->assertOk();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://bsky.test/xrpc/com.atproto.repo.deleteRecord'
        && $r->data() === ['repo' => 'did:plc:abc123', 'collection' => 'app.bsky.feed.post', 'rkey' => '3kabcdef']);
    Http::assertSent(fn (Request $r): bool => ($r->data()['rkey'] ?? null) === '3kghijkl');
});

it('deletes a Threads post', function () {
    Http::fake(['*' => Http::response(['success' => true, 'deleted_id' => '1789'], 200)]);
    $post = crmDelPost($this, $this->threads, '17890000000000001');
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.platform', 'threads');
    crmDelSentOnce('DELETE', rtrim((string) config('trypost.platforms.threads.graph_api'), '/').'/17890000000000001?access_token=');
});

it('deletes a Facebook page post', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $post = crmDelPost($this, $this->facebook, '1000200030004_5000600070008');
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.platform', 'facebook');
    crmDelSentOnce('DELETE', rtrim((string) config('trypost.platforms.facebook.graph_api'), '/').'/1000200030004_5000600070008?access_token=');
});

it('counts a post the network no longer has as deleted', function () {
    Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7003');
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.deleted', true);
    expect(Post::query()->find($post->id))->toBeNull()->and(crmDelRow($post)->deleted_at)->not->toBeNull();

    Http::fake(['*' => Http::response(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Unsupported delete request']], 400)]);
    $fb = crmDelPost($this, $this->facebook, '1_2');
    crmDel($this, ['post_id' => $fb->id])->assertOk();
    expect(Post::query()->find($fb->id))->toBeNull();
});

it('settles a post the network already shows as deleted without calling the network', function () {
    Http::fake();
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7004', ['publish_status' => PublishStatus::Deleted]);
    crmDel($this, ['post_id' => $post->id])->assertOk()->assertJsonPath('data.deleted', true);
    Http::assertNothingSent();
    expect(Post::query()->find($post->id))->toBeNull();
});

it('refuses Instagram and an unwired network with a sentence naming the network, calls nothing and changes nothing', function () {
    Http::fake();
    $ig = crmDelPost($this, $this->instagram, '17900000000000001');
    $tt = crmDelPost($this, $this->tiktok, '7300000000000000001');
    crmDel($this, ['post_id' => $ig->id])->assertStatus(422)->assertJsonPath('error', 'delete_not_supported')
        ->assertJsonPath('message', 'Instagram does not let a post be deleted from here. Delete it on the network itself.');
    crmDel($this, ['post_id' => $tt->id])->assertStatus(422)->assertJsonPath('error', 'delete_not_supported')
        ->assertJsonPath('message', 'TikTok does not let a post be deleted from here. Delete it on the network itself.');
    Http::assertNothingSent();
    crmDelUntouched($ig);
    crmDelUntouched($tt);
});

it('refuses a post that is not published, calls nothing and changes nothing', function () {
    Http::fake();
    $posts = [
        Post::factory()->forAccount($this->linkedin)->scheduled()->create(['user_id' => $this->direct->id]),
        Post::factory()->forAccount($this->linkedin)->draft()->create(['user_id' => $this->direct->id]),
        Post::factory()->forAccount($this->linkedin)->pendingApproval()->create(['user_id' => $this->direct->id]),
        Post::factory()->forAccount($this->linkedin)->failed()->create(['user_id' => $this->direct->id]),
        Post::factory()->forAccount($this->linkedin)->publishing()->create(['user_id' => $this->direct->id]), // no provider id stored yet
    ];
    foreach ($posts as $post) {
        crmDel($this, ['post_id' => $post->id])->assertStatus(422)->assertJsonPath('error', 'not_published')
            ->assertJsonPath('message', 'That post is not published yet, so there is nothing to delete.');
        crmDelUntouched($post);
    }
    Http::assertNothingSent();
});

it('does not know a post of another team, an unmapped workspace, or an unknown post', function () {
    Http::fake();
    $theirs = Post::factory()->forAccount($this->theirs)->published()->create(['user_id' => $this->other->user_id, 'platform_post_id' => 'urn:li:share:9']);
    $mine = crmDelPost($this, $this->linkedin, 'urn:li:share:7005');
    crmDel($this, ['post_id' => $theirs->id])->assertNotFound()->assertJsonPath('error', 'not_found');
    crmDel($this, ['post_id' => $mine->id, 'workspace' => 'ws_other'])->assertNotFound()->assertJsonPath('error', 'not_found');
    crmDel($this, ['post_id' => $mine->id, 'workspace' => 'ws_unknown'])->assertNotFound()->assertJsonPath('error', 'not_found');
    crmDel($this, ['post_id' => (string) str()->uuid()])->assertNotFound()->assertJsonPath('error', 'not_found');
    Http::assertNothingSent();
    crmDelUntouched($theirs);
    crmDelUntouched($mine);
});

it('answers unknown_person for an email outside the workspace, and forbidden for a member who may not delete this post', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7006');
    crmDel($this, ['post_id' => $post->id, 'actor_email' => 'stan@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmDel($this, ['post_id' => $post->id, 'actor_email' => 'nobody@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmDel($this, ['post_id' => $post->id, 'actor_email' => 'pat@example.test'])->assertForbidden()->assertJsonPath('error', 'forbidden')
        ->assertJsonStructure(['error', 'message']);
    Http::assertNothingSent();
    crmDelUntouched($post);
});

it('lets the writer, a workspace admin and the workspace owner delete', function () {
    Http::fake(['*' => Http::response('', 204)]);
    foreach (['dana@example.test', 'amy@example.test', $this->owner->email] as $i => $email) {
        $post = crmDelPost($this, $this->linkedin, "urn:li:share:80{$i}");
        crmDel($this, ['post_id' => $post->id, 'actor_email' => $email])->assertOk();
        expect(Post::query()->find($post->id))->toBeNull();
    }
});

it('answers channel_not_connected when the network no longer accepts the token, and changes nothing', function () {
    Http::fake(['*' => Http::response(['message' => 'Invalid access token'], 401)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7007');
    crmDel($this, ['post_id' => $post->id])->assertStatus(409)->assertJsonPath('error', 'channel_not_connected')
        ->assertJsonPath('message', 'Adi Murali is not connected any more. Connect it again in Content, then delete the post.');
    crmDelUntouched($post);

    Http::fake(['*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 400)]);
    $fb = crmDelPost($this, $this->facebook, '1_3');
    crmDel($this, ['post_id' => $fb->id])->assertStatus(409)->assertJsonPath('error', 'channel_not_connected');
    crmDelUntouched($fb);

    $off = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::X, 'display_name' => 'Old X', 'status' => Status::Disconnected]);
    $oldX = crmDelPost($this, $off, '185');
    Http::fake();
    crmDel($this, ['post_id' => $oldX->id])->assertStatus(409)->assertJsonPath('error', 'channel_not_connected');
    Http::assertNothingSent();
    crmDelUntouched($oldX);
});

it('answers upstream for a provider 500 or a timeout, and the post stays published so it can be tried again', function () {
    Http::fake(['*' => Http::response('boom', 500)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7008');
    crmDel($this, ['post_id' => $post->id])->assertStatus(502)->assertJsonPath('error', 'upstream')
        ->assertJsonPath('message', 'LinkedIn is not answering. Try again in a minute.');
    crmDelUntouched($post);

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    crmDel($this, ['post_id' => $post->id])->assertStatus(502)->assertJsonPath('error', 'upstream');
    crmDelUntouched($post);
    expect(crmDelRow($post)->status)->toBe(PostStatus::Published);

    Http::fake(['*' => Http::response('', 204)]);
    crmDel($this, ['post_id' => $post->id])->assertOk();
});

it('answers with a reconnect sentence when the stored token lacks the permission to delete', function () {
    Http::fake(['*' => Http::response(['message' => 'Not enough permissions'], 403)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7009');
    $r = crmDel($this, ['post_id' => $post->id])->assertStatus(422)->assertJsonPath('error', 'delete_not_supported');
    expect($r->json('message'))->toContain('Connect the channel again in Content');
    crmDelUntouched($post);
});

it('never puts a token, the provider body or the word Exception in an answer', function () {
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7010');
    $fakes = [
        Http::response('SECRET-PROVIDER-BODY tok-secret-abc', 401),
        Http::response('SECRET-PROVIDER-BODY tok-secret-abc', 500),
        Http::response('SECRET-PROVIDER-BODY tok-secret-abc', 400),
        Http::response('SECRET-PROVIDER-BODY tok-secret-abc', 403),
        fn () => throw new ConnectionException('cURL error 7: tok-secret-abc Exception'),
    ];
    foreach ($fakes as $fake) {
        Http::fake(['*' => $fake]);
        $r = crmDel($this, ['post_id' => $post->id]);
        expect($r->getStatusCode())->toBeIn([409, 502, 422]);
        foreach (['tok-secret-abc', 'SECRET-PROVIDER-BODY', 'Exception', 'Stack trace', 'api.linkedin'] as $leak) {
            expect($r->getContent())->not->toContain($leak);
        }
        $r->assertHeader('Cache-Control', 'no-store, private');
    }
    Http::fake(['*' => Http::response('', 204)]);
    $ok = crmDel($this, ['post_id' => $post->id])->assertOk();
    expect($ok->getContent())->not->toContain('tok-secret-abc')->not->toContain('Exception');
});

it('answers not_found the second time and makes no provider call', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $post = crmDelPost($this, $this->linkedin, 'urn:li:share:7011');
    crmDel($this, ['post_id' => $post->id])->assertOk();
    crmDel($this, ['post_id' => $post->id])->assertNotFound()->assertJsonPath('error', 'not_found');
    Http::assertSentCount(1);
});

it('refuses a missing or malformed body', function () {
    Http::fake();
    crmDel($this, ['post_id' => 'not-a-uuid'])->assertUnprocessable()->assertJsonPath('error', 'validation');
    crmDel($this, ['post_id' => (string) str()->uuid(), 'actor_email' => 'not-an-email'])->assertUnprocessable()->assertJsonPath('error', 'validation');
    Http::assertNothingSent();
});
