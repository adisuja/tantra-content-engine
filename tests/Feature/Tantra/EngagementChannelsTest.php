<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Tantra\Engagement\ForwardTelegramUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

// tantra: the CRM's engagement channels endpoint and the Telegram forward (ENGAGEMENT_CHANNELS_CONTRACT.md).

beforeEach(function () {
    $owner = User::factory()->create();
    $this->adi = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $this->ben = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $stranger = User::factory()->create();
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);

    config([
        'tantra.engagement_channels.key' => 'chan-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->adi->id}|{$this->ben->id},ws_other={$this->other->id}",
        'tantra.engagement_channels.telegram_secret' => 'tg-secret',
        'tantra.engagement_channels.telegram_forward_url' => 'https://crm.test/hooks/engagement/telegram',
        'trypost.platforms.telegram.bot_token' => 'bot-token-tg',
        'trypost.platforms.telegram.bot_username' => 'TantraBot',
        'trypost.platforms.telegram.webhook_secret' => 'shh-secret',
        'trypost.platforms.discord.bot_token' => 'bot-token-dc',
    ]);

    $this->bsky = SocialAccount::factory()->create([
        'workspace_id' => $this->adi->id, 'platform' => Platform::Bluesky, 'platform_user_id' => 'did:plc:abc', 'username' => 'tantra.bsky.social',
        'display_name' => 'Tantra', 'access_token' => 'bsky-access', 'refresh_token' => 'bsky-refresh', 'token_expires_at' => now()->addHours(2),
        'meta' => ['service' => 'https://bsky.social'], 'status' => Status::Connected,
    ]);
    $this->igfb = SocialAccount::factory()->create([
        'workspace_id' => $this->ben->id, 'platform' => Platform::InstagramFacebook, 'platform_user_id' => '1784', 'access_token' => 'page-token',
        'token_expires_at' => null, 'meta' => ['page_id' => '555'], 'status' => Status::Connected,
    ]);
    $this->discord = SocialAccount::factory()->create([
        'workspace_id' => $this->adi->id, 'platform' => Platform::Discord, 'platform_user_id' => 'guild-1', 'access_token' => 'user-oauth', 'status' => Status::Connected,
    ]);
    $this->telegram = SocialAccount::factory()->create([
        'workspace_id' => $this->ben->id, 'platform' => Platform::Telegram, 'platform_user_id' => '-1001', 'access_token' => '',
        'meta' => ['chat_id' => '-1001'], 'status' => Status::Connected,
    ]);
    // Never part of Engagement channels, and never another Account's.
    SocialAccount::factory()->create(['workspace_id' => $this->adi->id, 'platform' => Platform::LinkedIn, 'access_token' => 'li-token']);
    SocialAccount::factory()->create(['workspace_id' => $this->other->id, 'platform' => Platform::Bluesky, 'access_token' => 'other-token', 'status' => Status::Connected]);
});

function channels(object $test, array $body, ?string $key = 'chan-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-engagement']))
        ->postJson('/api/tantra/engagement/channels', $body);
}

it('answers 401 to a missing, wrong or unset key', function () {
    channels($this, ['workspace' => 'ws_team'], null)->assertUnauthorized();
    channels($this, ['workspace' => 'ws_team'], 'nope')->assertUnauthorized();
    config(['tantra.engagement_channels.key' => null]);
    channels($this, ['workspace' => 'ws_team'], '')->assertUnauthorized();
});

it('gives an unmapped workspace nothing, and never falls back to a team name', function () {
    channels($this, ['workspace' => 'ws_unknown', 'team' => 'tantra'])->assertOk()->assertExactJson(['accounts' => []]);
    channels($this, ['team' => 'tantra'])->assertOk()->assertExactJson(['accounts' => []]);
});

it('lists the mapped workspaces accounts without any token', function () {
    $r = channels($this, ['workspace' => 'ws_team', 'include_tokens' => false])->assertOk()->assertHeader('Cache-Control');
    $accounts = collect($r->json('accounts'))->keyBy('platform');

    expect($accounts->keys()->sort()->values()->all())->toBe(['bluesky', 'discord', 'instagram', 'telegram'])
        ->and($accounts['bluesky'])->toMatchArray(['user_id' => 'did:plc:abc', 'username' => 'tantra.bsky.social', 'status' => 'connected', 'meta' => ['service' => 'https://bsky.social']])
        ->and($accounts['instagram']['meta'])->toBe(['page_id' => '555'])
        ->and($accounts['discord']['meta'])->toBe(['guild_id' => 'guild-1'])
        ->and($accounts['telegram']['meta'])->toBe(['chat_id' => '-1001', 'bot_username' => 'TantraBot']);
    expect($r->getContent())->not->toContain('bsky-access')->not->toContain('page-token')->not->toContain('bot-token')->not->toContain('other-token')->not->toContain('li-token');
});

it('gives tokens only when asked for a read or a send', function () {
    $listing = channels($this, ['workspace' => 'ws_team', 'include_tokens' => true])->assertOk();
    expect($listing->getContent())->not->toContain('bsky-access');
    channels($this, ['workspace' => 'ws_team', 'include_tokens' => true, 'purpose' => 'list'])->assertOk()->assertJsonMissingPath('accounts.0.access_token');

    $r = channels($this, ['workspace' => 'ws_team', 'platform' => 'bluesky', 'include_tokens' => true, 'purpose' => 'send'])->assertOk();
    expect($r->json('accounts'))->toHaveCount(1)
        ->and($r->json('accounts.0.access_token'))->toBe('bsky-access')
        ->and($r->json('accounts.0.token_expires_at'))->toEndWith('Z');

    $bots = collect(channels($this, ['workspace' => 'ws_team', 'include_tokens' => true, 'purpose' => 'read'])->json('accounts'))->keyBy('platform');
    expect($bots['telegram']['meta']['bot_token'])->toBe('bot-token-tg')
        ->and($bots['discord']['meta'])->toBe(['guild_id' => 'guild-1', 'bot_token' => 'bot-token-dc'])
        ->and($bots['instagram']['access_token'])->toBe('page-token');
});

it('refreshes an expiring token before handing it out, and logs no token when that fails', function () {
    $this->bsky->update(['token_expires_at' => now()->addMinutes(5)]);
    $this->mock(ConnectionVerifier::class)->shouldReceive('refreshToken')->once()->andReturnUsing(function (SocialAccount $a): bool {
        $a->update(['access_token' => 'bsky-fresh', 'token_expires_at' => now()->addHours(2)]);

        return true;
    });

    channels($this, ['workspace' => 'ws_team', 'platform' => 'bluesky', 'include_tokens' => true, 'purpose' => 'read'])
        ->assertOk()->assertJsonPath('accounts.0.access_token', 'bsky-fresh');
});

it('never hands out a dead token when the refresh fails', function () {
    $this->bsky->update(['token_expires_at' => now()->subMinute()]);
    $this->mock(ConnectionVerifier::class)->shouldReceive('refreshToken')->once()->andThrow(new RuntimeException('rejected bsky-refresh'));
    Log::spy();

    channels($this, ['workspace' => 'ws_team', 'platform' => 'bluesky', 'include_tokens' => true, 'purpose' => 'send'])
        ->assertOk()
        ->assertJsonPath('accounts.0.status', 'disconnected')
        ->assertJsonPath('accounts.0.access_token', null);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx): bool => ! str_contains(json_encode($ctx), 'bsky'))->once();
});

it('filters by CRM platform, with both Instagram connections under instagram', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->adi->id, 'platform' => Platform::Instagram, 'platform_user_id' => 'x', 'meta' => ['ig_user_id' => '999'], 'status' => Status::Connected]);

    $ig = channels($this, ['workspace' => 'ws_team', 'platform' => 'instagram'])->assertOk()->json('accounts');
    expect(collect($ig)->pluck('user_id')->all())->toEqualCanonicalizing(['1784', '999']);
    channels($this, ['workspace' => 'ws_team', 'platform' => 'linkedin'])->assertUnprocessable();
});

it('rate limits the endpoint', function () {
    config(['tantra.engagement_channels.per_minute' => 2]);
    channels($this, ['workspace' => 'ws_team'])->assertOk();
    channels($this, ['workspace' => 'ws_team'])->assertOk();
    channels($this, ['workspace' => 'ws_team'])->assertTooManyRequests();
});

it('forwards a private bot chat to each mapped workspace with a Telegram channel, signed for that workspace', function () {
    Http::fake(['crm.test/*' => Http::response(['ok' => true])]);
    $update = ['update_id' => 7, 'message' => ['message_id' => 1, 'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/start PROMPTS']];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')->postJson(route('telegram.webhook'), $update)->assertNoContent();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://crm.test/hooks/engagement/telegram/ws_team'
        && $r->header('X-Tantra-Signature')[0] === hash_hmac('sha256', 'ws_team', 'tg-secret')
        && $r->header('X-Tantra-Signature')[0] !== hash_hmac('sha256', 'ws_other', 'tg-secret')
        && ! $r->hasHeader('X-Telegram-Bot-Api-Secret-Token')
        && json_decode($r->body(), true) === $update);
    expect(ForwardTelegramUpdate::signature('ws_team'))->not->toBe(ForwardTelegramUpdate::signature('ws_other'));
});

it('does not forward the connect flow, channel posts, a bad secret, or anything without the forward secret', function () {
    Http::fake();
    $send = fn (array $u, string $secret = 'shh-secret') => $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson(route('telegram.webhook'), $u);

    $send(['update_id' => 1, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'text' => '/connect abc']]);
    $send(['update_id' => 2, 'channel_post' => ['chat' => ['id' => -1001, 'type' => 'channel'], 'text' => 'PROMPTS']]);
    $send(['update_id' => 3, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'text' => 'PROMPTS']], 'wrong')->assertForbidden();
    config(['tantra.engagement_channels.telegram_secret' => null]);
    $send(['update_id' => 4, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'text' => 'PROMPTS']]);

    Http::assertNothingSent();
});
