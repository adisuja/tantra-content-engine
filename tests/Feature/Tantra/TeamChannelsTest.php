<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostPlatformMetaRules;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipilePublisher;
use App\Tantra\Unipile\UnipilePublishException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);

    $this->adi = User::factory()->create(['name' => 'Adithya Murali']);
    $this->ws = fn (string $name): Workspace => tap(
        Workspace::factory()->create(['name' => $name, 'account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]),
        fn (Workspace $w) => $w->members()->attach($this->adi->id, membershipPivot('admin')),
    );
    $this->adiWs = ($this->ws)('Adi');
    $this->benWs = ($this->ws)('Ben');
    $this->tantraWs = ($this->ws)('Tantra');
    $this->adi->update(['current_workspace_id' => $this->adiWs->id]);

    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'Ben Basil Tomy']);
    $this->benWs->members()->attach($this->ben->id, membershipPivot('admin'));
    MemberProfile::query()->create(['user_id' => $this->ben->id, 'unipile_account_id' => 'acc_ben', 'unipile_name' => 'Ben Basil Tomy']);
    MemberProfile::query()->create(['user_id' => $this->adi->id, 'unipile_account_id' => 'acc_adi', 'unipile_name' => 'Adithya Murali']);

    // Adi's own LinkedIn, connected directly in the Engine.
    SocialAccount::factory()->create(['workspace_id' => $this->adiWs->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adithya Murali']);
});

function fakeTeam(array $extra = []): void
{
    Http::fake([
        'unipile.test/v2/accounts*' => Http::response(['data' => [
            ['id' => 'acc_adi', 'name' => 'Adithya Murali', 'type' => 'LINKEDIN', 'status' => 'running'],
            ['id' => 'acc_ben', 'name' => 'Ben Basil Tomy', 'type' => 'LINKEDIN', 'status' => 'running'],
            ['id' => 'acc_arjun', 'name' => 'Arjun Sashi', 'type' => 'LINKEDIN', 'status' => 'running'],
            ['id' => 'acc_abbey', 'name' => 'Abbey Smith', 'type' => 'LINKEDIN', 'status' => 'disconnected'],
            ['id' => 'acc_wa', 'name' => '+91700', 'type' => 'WHATSAPP', 'status' => 'running'],
            ...$extra,
        ]]),
        'api.tantra.test/api/v1/channels/accounts' => Http::response([
            ['id' => 'ta_ben', 'channel' => 'linkedin', 'displayName' => 'Ben Basil Tomy', 'status' => 'running'],
        ]),
    ]);
}

it('puts each Tantra account in its person\'s workspace and keeps a direct LinkedIn as it is', function () {
    fakeTeam();

    $this->artisan('tantra:sync-channels')->assertSuccessful();

    $ben = SocialAccount::query()->where('platform', Platform::UnipileLinkedIn)->where('platform_user_id', 'acc_ben')->first();
    expect($ben)->not->toBeNull()
        ->and($ben->workspace_id)->toBe($this->benWs->id)
        ->and($ben->status)->toBe(Status::Connected)
        ->and($ben->display_name)->toBe('Ben Basil Tomy')
        ->and($ben->meta['provider'])->toBe('unipile');
    // Adi already has his LinkedIn connected directly: no second copy.
    expect(SocialAccount::query()->where('platform_user_id', 'acc_adi')->exists())->toBeFalse();
    // WhatsApp is not a publishing channel; Arjun has no workspace yet.
    expect(SocialAccount::query()->where('platform_user_id', 'acc_wa')->exists())->toBeFalse()
        ->and(SocialAccount::query()->where('platform_user_id', 'acc_arjun')->exists())->toBeFalse();
});

it('ignores accounts that are not the team, even with a workspace of the same first name', function () {
    fakeTeam([['id' => 'acc_client', 'name' => 'Ben Carter', 'type' => 'LINKEDIN', 'status' => 'running']]);

    $this->artisan('tantra:sync-channels', ['--create-workspaces' => true])->assertSuccessful();

    expect(SocialAccount::query()->where('platform_user_id', 'acc_client')->exists())->toBeFalse()
        ->and(Workspace::query()->where('name', 'Arjun')->exists())->toBeFalse();
});

it('gives a named person with no workspace their own, and only when asked', function () {
    fakeTeam();

    $this->artisan('tantra:sync-channels', ['--create-workspaces' => true, '--person' => ['Arjun Sashi', 'Abbey Smith']])->assertSuccessful();

    $arjun = Workspace::query()->where('name', 'Arjun')->first();
    expect($arjun)->not->toBeNull()
        ->and(SocialAccount::query()->where('platform_user_id', 'acc_arjun')->value('workspace_id'))->toBe($arjun->id)
        ->and($arjun->members()->whereKey($this->adi->id)->exists())->toBeTrue();
    // A disconnected account never creates a workspace.
    expect(Workspace::query()->where('name', 'Abbey')->exists())->toBeFalse();
    // Running it again changes nothing.
    $this->artisan('tantra:sync-channels', ['--create-workspaces' => true, '--person' => ['Arjun Sashi']])->assertSuccessful();
    expect(Workspace::query()->where('name', 'Arjun')->count())->toBe(1)
        ->and(SocialAccount::query()->where('platform_user_id', 'acc_arjun')->count())->toBe(1);
});

it('records the Tantra API account and marks an account gone from Unipile as disconnected', function () {
    AiConnector::query()->create(['account_id' => $this->adi->account_id, 'provider' => 'tantra_api', 'secret' => 'tk_live_x', 'base_url' => 'https://api.tantra.test/api/v1', 'is_active' => true]);
    fakeTeam();
    $this->artisan('tantra:sync-channels')->assertSuccessful();

    $ben = SocialAccount::query()->where('platform_user_id', 'acc_ben')->first();
    expect($ben->meta['tantra_account_ids'])->toBe(['ta_ben']);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/channels/accounts') && $r->header('X-API-Key')[0] === 'tk_live_x');

    Http::swap(new Factory);
    Http::fake([
        'unipile.test/v2/accounts*' => Http::response(['data' => [['id' => 'acc_arjun', 'name' => 'Arjun Sashi', 'type' => 'LINKEDIN', 'status' => 'running']]]),
        'api.tantra.test/*' => Http::response([]),
    ]);
    $this->artisan('tantra:sync-channels')->assertSuccessful();
    expect($ben->fresh()->status)->toBe(Status::Disconnected);
});

it('lists the channels of the member\'s other workspaces, and opening one switches workspace', function () {
    $page = SocialAccount::factory()->create(['workspace_id' => $this->tantraWs->id, 'platform' => Platform::ZernioLinkedInPage, 'display_name' => 'Tantra', 'access_token' => 'zernio-managed']);

    $this->actingAs($this->adi)->get('/tantra/review')->assertInertia(fn ($p) => $p
        ->where('tantraOtherChannels', fn ($groups) => collect($groups)->contains(fn ($g) => $g['name'] === 'Tantra' && $g['channels'][0]['id'] === $page->id))
    );

    $this->actingAs($this->adi)->post("/tantra/channels/{$page->id}/open")->assertRedirect(route('app.channels.publish', $page));
    expect($this->adi->fresh()->current_workspace_id)->toBe($this->tantraWs->id);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post("/tantra/channels/{$page->id}/open")->assertForbidden();
});

it('publishes through Unipile once, then adds the first comment once', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->benWs->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_ben', 'access_token' => 'unipile-managed']);
    $post = Post::factory()->forAccount($channel)->create([
        'user_id' => $this->ben->id, 'content' => 'Three numbers every RevOps lead should know.',
        'content_type' => ContentType::UnipileLinkedInPost,
        'meta' => ['thread_replies' => [['text' => 'The calculator: https://example.com']]],
    ]);
    $post->setRelation('socialAccount', $channel);
    Http::fake([
        'unipile.test/v2/acc_ben/posts' => Http::response(['id' => '7400', 'share_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7400'], 201),
        'unipile.test/v2/acc_ben/posts/7400/comments' => Http::response(['id' => 'c1'], 201),
    ]);

    $first = app(UnipilePublisher::class)->publish($post);
    $again = app(UnipilePublisher::class)->publish($post->fresh()->setRelation('socialAccount', $channel));

    expect($first)->toBe(['id' => '7400', 'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7400', 'thread_reply_ids' => ['c1']])
        ->and($again['id'])->toBe('7400');
    Http::assertSentCount(2); // one post, one comment: the retry posted nothing
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://unipile.test/v2/acc_ben/posts' && $r['text'] === 'Three numbers every RevOps lead should know.');
});

it('turns a Unipile refusal into a clear publish error', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->benWs->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_ben', 'access_token' => 'unipile-managed']);
    $post = Post::factory()->forAccount($channel)->create(['user_id' => $this->ben->id, 'content' => 'x', 'content_type' => ContentType::UnipileLinkedInPost]);
    $post->setRelation('socialAccount', $channel);
    Http::fake(['unipile.test/v2/acc_ben/posts' => Http::response(['title' => 'Unauthorized', 'detail' => 'The account needs a reconnection.'], 401)]);

    expect(fn () => app(UnipilePublisher::class)->publish($post))->toThrow(UnipilePublishException::class, 'The account needs a reconnection.');
    expect($post->fresh()->meta['unipile_post_id'] ?? null)->toBeNull();
});

it('never posts twice when Unipile does not confirm the post', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->benWs->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_ben', 'access_token' => 'unipile-managed']);
    $post = Post::factory()->forAccount($channel)->create(['user_id' => $this->ben->id, 'content' => 'x', 'content_type' => ContentType::UnipileLinkedInPost]);
    $post->setRelation('socialAccount', $channel);
    Http::fake(['unipile.test/v2/acc_ben/posts' => Http::response('gateway timeout', 504)]);

    expect(fn () => app(UnipilePublisher::class)->publish($post))->toThrow(UnipilePublishException::class, 'did not confirm');
    expect(fn () => app(UnipilePublisher::class)->publish($post->fresh()->setRelation('socialAccount', $channel)))->toThrow(UnipilePublishException::class, 'did not confirm');
    Http::assertSentCount(1);
});

it('lets a duplicated post publish again instead of reusing the old LinkedIn post', function () {
    $meta = ['unipile_post_id' => '7400', 'unipile_post_url' => 'u', 'unipile_first_comment_id' => 'c1', 'thread_replies' => [['text' => 'hi']]];

    expect(Arr::except($meta, PostPlatformMetaRules::SYSTEM_KEYS))->toBe(['thread_replies' => [['text' => 'hi']]]);
});
