<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mail\AccountDisconnected;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Social\PendingConnection;
use App\Tantra\Channels\CrmConnectLink;
use App\Tantra\Crm\CrmPaths;
use App\Tantra\Notify\CoreEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/GatewayHelpers.php';

// tantra: One Content app PRD 4.1 (lane E1): the account picker, channel care, the composer helpers, the Engine card and signed media.

beforeEach(function () {
    Cache::flush();
    oneGatewaySetUp($this);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    $this->owner->forceFill(['clerk_user_id' => ONE_GW_CLERK])->save();
    config(['trypost.platforms.bluesky.enabled' => true]);
});

function fakeBlueskySignIn(): void
{
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'emailConfirmed' => true, 'did' => 'did:plc:picker123', 'handle' => 'picker.bsky.social',
            'accessJwt' => 'access', 'refreshJwt' => 'refresh',
        ]),
        'https://bsky.social/xrpc/com.atproto.server.getSession' => Http::response(['did' => 'did:plc:picker123', 'handle' => 'picker.bsky.social', 'emailConfirmed' => true]),
        'https://bsky.social/xrpc/app.bsky.actor.getProfile*' => Http::response(['did' => 'did:plc:picker123', 'handle' => 'picker.bsky.social', 'displayName' => 'Picker']),
        'https://public.api.bsky.app/*' => Http::response(['did' => 'did:plc:picker123', 'handle' => 'picker.bsky.social', 'displayName' => 'Picker']),
    ]);
}

describe('account picker', function () {
    it('sends the finish page to the CRM and connects what is picked there over the gateway', function () {
        config(['tantra.crm.account_picker' => true]);
        fakeBlueskySignIn();
        $this->actingAs($this->owner)->post(route('app.social.bluesky.store'), ['identifier' => 'picker.bsky.social', 'password' => 'xxxx-xxxx-xxxx-xxxx'])
            ->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

        $finish = $this->actingAs($this->owner)->get(route('app.social.connect.show', Platform::Bluesky));
        $location = (string) $finish->headers->get('Location');
        expect($location)->toStartWith('https://crm.usetantra.com/content/channels?finish=')
            ->and(PendingConnection::current())->toBeNull();
        $token = Str::after($location, 'finish=');

        oneGatewayCall($this, 'GET', "/api/gateway/tantra/connect/{$token}/candidates")->assertOk()
            ->assertJsonPath('data.state', 'select')
            ->assertJsonPath('data.identities.0.key', 'bluesky:did:plc:picker123')
            ->assertJsonMissingPath('data.identities.0.attributes');

        oneGatewayCall($this, 'POST', "/api/gateway/tantra/connect/{$token}/select", ['identities' => ['bluesky:did:plc:nope']])->assertStatus(422);
        oneGatewayCall($this, 'POST', "/api/gateway/tantra/connect/{$token}/select", ['identities' => ['bluesky:did:plc:picker123']])
            ->assertCreated()->assertJsonPath('data.0.platform', 'bluesky');

        expect(SocialAccount::query()->where('workspace_id', $this->ws->id)->where('platform_user_id', 'did:plc:picker123')->exists())->toBeTrue();
        // Used up.
        oneGatewayCall($this, 'GET', "/api/gateway/tantra/connect/{$token}/candidates")->assertNotFound();
    });

    it('keeps the engine confirmation page when the picker is off and the connection did not start in the CRM', function () {
        config(['tantra.crm.account_picker' => false]);
        fakeBlueskySignIn();
        $this->actingAs($this->owner)->post(route('app.social.bluesky.store'), ['identifier' => 'picker.bsky.social', 'password' => 'xxxx-xxxx-xxxx-xxxx']);

        $this->actingAs($this->owner)->get(route('app.social.connect.show', Platform::Bluesky))->assertOk();
    });

    it('always uses the picker for a connection started from Content > Channels in the CRM, and says where to go back', function () {
        config(['tantra.crm.account_picker' => false]);
        fakeBlueskySignIn();
        CrmConnectLink::remember('bluesky', 'https://crm.usetantra.com/content/channels');
        $this->actingAs($this->owner)->post(route('app.social.bluesky.store', ['tantra_crm' => '1']), ['identifier' => 'picker.bsky.social', 'password' => 'xxxx-xxxx-xxxx-xxxx']);

        $location = (string) $this->actingAs($this->owner)->get(route('app.social.connect.show', Platform::Bluesky))->headers->get('Location');
        expect($location)->toStartWith('https://crm.usetantra.com/content/channels?finish=');

        oneGatewayCall($this, 'GET', '/api/gateway/tantra/connect/'.Str::after($location, 'finish=').'/candidates')->assertOk()
            ->assertJsonPath('data.return_url', 'https://crm.usetantra.com/content/channels?network=bluesky&result=connected');
    });

    it('never shows a token to someone else', function () {
        oneGatewayCall($this, 'GET', '/api/gateway/tantra/connect/'.Str::random(48).'/candidates')->assertNotFound();
    });
});

describe('connect and channel care', function () {
    it('connects Bluesky with an app password in one call', function () {
        fakeBlueskySignIn();

        oneGatewayCall($this, 'POST', '/api/gateway/tantra/connect/bluesky', ['identifier' => 'picker.bsky.social', 'password' => 'xxxx-xxxx-xxxx-xxxx'])
            ->assertCreated()->assertJsonPath('data.0.platform', 'bluesky')->assertJsonPath('data.0.connected', true);

        expect(SocialAccount::query()->where('workspace_id', $this->ws->id)->where('platform', Platform::Bluesky)->count())->toBe(1);
    });

    it('answers a signed link for Mastodon that starts the sign in on the named server', function () {
        config(['trypost.platforms.mastodon.enabled' => true]);
        Http::fake(['https://mastodon.social/api/v1/apps' => Http::response(['client_id' => 'cid', 'client_secret' => 'secret', 'id' => '1'])]);

        $url = oneGatewayCall($this, 'POST', '/api/gateway/tantra/connect/mastodon', ['instance' => 'https://mastodon.social'])
            ->assertOk()->json('data.url');
        expect($url)->toContain('tantra/crm-connect')->toContain('network=mastodon')->toContain('instance=');

        $answer = $this->get($url);
        expect((string) $answer->headers->get('Location'))->toStartWith('https://mastodon.social/oauth/authorize?');
    });

    it('reorders and disconnects channels', function () {
        $a = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn]);
        $b = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::Threads]);

        oneGatewayCall($this, 'PUT', '/api/gateway/tantra/channels/order', ['ids' => [$b->id, $a->id]])->assertOk()
            ->assertJsonPath('data', [$b->id, $a->id]);

        oneGatewayCall($this, 'DELETE', "/api/gateway/tantra/channels/{$a->id}")->assertOk();
        expect(SocialAccount::query()->find($a->id))->toBeNull();
        oneGatewayCall($this, 'DELETE', '/api/gateway/tantra/channels/'.Str::uuid())->assertNotFound();
    });

    it('lists the composer options of each channel', function () {
        $threads = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::Threads]);
        $youtube = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::YouTube]);

        oneGatewayCall($this, 'GET', "/api/gateway/tantra/channels/{$threads->id}/options")->assertOk()
            ->assertJsonPath('data.platform', 'threads')
            ->assertJsonPath('data.reply_chain.supported', true)
            ->assertJsonPath('data.reply_control.0', 'everyone');
        oneGatewayCall($this, 'GET', "/api/gateway/tantra/channels/{$youtube->id}/options")->assertOk()
            ->assertJsonPath('data.title.required', true)
            ->assertJsonPath('data.playlist.field', 'playlist_id');
    });
});

describe('composer', function () {
    it('answers what the CRM AI task content.assist needs', function () {
        oneGatewayCall($this, 'POST', '/api/gateway/tantra/composer/assist', ['action' => 'hooks', 'text' => 'One idea.', 'platform' => 'linkedin'])
            ->assertOk()->assertJsonPath('data.handled_by', 'crm')->assertJsonPath('data.ai_task', 'content.assist')
            ->assertJsonPath('data.variants', 3)->assertJsonPath('data.max_length', Platform::LinkedIn->maxContentLength());
        oneGatewayCall($this, 'POST', '/api/gateway/tantra/composer/assist', ['action' => 'poem', 'text' => 'x'])->assertStatus(422);
    });

    it('searches Unsplash with the engine key', function () {
        config(['services.unsplash.access_key' => null]);

        oneGatewayCall($this, 'GET', '/api/gateway/tantra/unsplash?query=desk')->assertOk()->assertJsonPath('data.results', []);
    });

    it('duplicates a post of this workspace only', function () {
        $channel = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn]);
        $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'social_account_id' => $channel->id, 'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost]);
        $before = Post::query()->count();

        $r = oneGatewayCall($this, 'POST', "/api/gateway/tantra/posts/{$post->id}/duplicate");
        $r->assertCreated();
        expect(Post::query()->count())->toBe($before + 1);

        $other = Post::factory()->create();
        oneGatewayCall($this, 'POST', "/api/gateway/tantra/posts/{$other->id}/duplicate")->assertNotFound();
    });
});

describe('engine card', function () {
    it('shows health, queue depth and failed jobs to the instance owner, and retries one', function () {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Thing']), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);

        $r = oneGatewayCall($this, 'GET', '/api/gateway/tantra/ops')->assertOk()
            ->assertJsonPath('data.instance_owner', true)
            ->assertJsonPath('data.failed_jobs.0.uuid', $uuid)
            ->assertJsonPath('data.failed_jobs.0.job', 'App\\Jobs\\Thing')
            ->assertJsonPath('data.failed_jobs.0.error', 'RuntimeException: boom');
        expect($r->json('data'))->toHaveKeys(['queues', 'queue_depth', 'failed_jobs_24h', 'developer_apps']);

        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => [$uuid]])->andReturn(0);
        oneGatewayCall($this, 'POST', "/api/gateway/tantra/ops/failed-jobs/{$uuid}/retry")->assertStatus(202);
        oneGatewayCall($this, 'POST', '/api/gateway/tantra/ops/failed-jobs/'.Str::uuid().'/retry')->assertNotFound();
    });
});

describe('signed media', function () {
    it('returns signed links that work without a sign in, and the old file route sends its visitors there', function () {
        Storage::fake();
        $media = Media::factory()->temporaryUpload($this->ws)->create(['path' => 'medias/one.png', 'mime_type' => 'image/png']);
        Storage::put('medias/one.png', 'png-bytes');

        $signed = oneGatewayCall($this, 'GET', '/api/gateway/tantra/media')->assertOk()->json('data.0.signed_url');
        expect($signed)->toContain('/tantra/media/'.$media->id.'/file')->toContain('signature=');

        auth()->logout();
        $file = $this->get($signed)->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($file->streamedContent())->toBe('png-bytes');
        $this->get('/tantra/media/'.$media->id.'/file')->assertForbidden();

        $this->actingAs($this->owner)->get(route('app.media.file', $media))->assertRedirectContains('/tantra/media/'.$media->id.'/file');
    });
});

describe('mail links', function () {
    it('point at CRM paths', function () {
        expect(CrmPaths::reconnect('abc'))->toBe('https://crm.usetantra.com/content/channels?reconnect=abc')
            ->and(CrmPaths::review())->toBe('https://crm.usetantra.com/content/review')
            ->and(CrmPaths::content())->toBe('https://crm.usetantra.com/content');

        // The Tantra kit renders the engine's emails (Tantra branding on), and the event data carries the same link.
        config(['tantra.branding.enabled' => true]);
        $account = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn]);
        $mail = new AccountDisconnected($account);
        $html = $mail->render();
        expect(CoreEvents::dataFor($mail, $this->owner)['url'])->toBe(CrmPaths::reconnect((string) $account->id));
        expect($html)->toContain('https://crm.usetantra.com/content/channels?reconnect='.$account->id);
    });
});
