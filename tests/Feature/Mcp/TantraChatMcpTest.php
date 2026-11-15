<?php

declare(strict_types=1);

use App\Enums\Mcp\Method;
use App\Models\User;
use Illuminate\Testing\TestResponse;

const TANTRA_CHAT_TEST_SECRET = 'test-chat-signing-secret-0123456789abcdef';

/**
 * A chat token exactly as the CRM and the chat Worker mint it (HS256, iss tantra-crm, aud tantra-chat).
 *
 * @param  array<string, mixed>  $claims
 */
function tantraChatToken(array $claims = [], string $secret = TANTRA_CHAT_TEST_SECRET): string
{
    $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $h = $b64(json_encode(['alg' => 'HS256']));
    $p = $b64(json_encode(array_merge([
        'sub' => 'user_crm_1', 'ws' => 'ws_crm_1', 'iss' => 'tantra-crm', 'aud' => 'tantra-chat',
        'iat' => time(), 'exp' => time() + 300,
    ], $claims)));
    $s = $b64(hash_hmac('sha256', "{$h}.{$p}", $secret, true));

    return "cs_{$h}.{$p}.{$s}";
}

/**
 * @param  array<string, mixed>  $params
 */
function tantraChatMcp(object $test, string $token, string $method, array $params = [], string $route = 'mcp.tantra-chat'): TestResponse
{
    return $test->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route($route), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ]);
}

beforeEach(function () {
    config(['tantra_chat.signing_secret' => TANTRA_CHAT_TEST_SECRET]);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->user->forceFill(['email' => 'Chat.Person@Example.test', 'email_verified_at' => now()])->save();
});

test('a chat token for a verified email uses the tools as that person in their workspace', function () {
    $token = tantraChatToken(['em' => 'chat.person@example.test']);

    $list = tantraChatMcp($this, $token, Method::ToolsList->value)->assertOk();
    expect(collect($list->json('result.tools'))->pluck('name'))->toContain('get-workspace-tool', 'list-posts-tool');

    $call = tantraChatMcp($this, $token, Method::ToolsCall->value, ['name' => 'get-workspace-tool', 'arguments' => (object) []])->assertOk();
    expect(json_encode($call->json('result')))->toContain((string) $this->workspace->id);
});

test('the route is off until the signing secret is set', function () {
    config(['tantra_chat.signing_secret' => null]);
    tantraChatMcp($this, tantraChatToken(['em' => 'chat.person@example.test']), Method::ToolsList->value)->assertNotFound();
});

test('forged, expired, wrong-audience and malformed tokens are refused', function (callable $make) {
    tantraChatMcp($this, $make(), Method::ToolsList->value)->assertUnauthorized();
})->with([
    'other secret' => [fn () => tantraChatToken(['em' => 'chat.person@example.test'], 'another-secret-another-secret-xxxxxx')],
    'expired' => [fn () => tantraChatToken(['em' => 'chat.person@example.test', 'exp' => time() - 120])],
    'wrong audience' => [fn () => tantraChatToken(['em' => 'chat.person@example.test', 'aud' => 'someone-else'])],
    'wrong issuer' => [fn () => tantraChatToken(['em' => 'chat.person@example.test', 'iss' => 'evil'])],
    'no email' => [fn () => tantraChatToken()],
    'not a chat token' => [fn () => 'ck_team_api_key'],
    'tampered' => [fn () => substr(tantraChatToken(['em' => 'chat.person@example.test']), 0, -3).'abc'],
]);

test('an email with no engine account, or one not yet verified, gets no access', function () {
    tantraChatMcp($this, tantraChatToken(['em' => 'nobody@example.test']), Method::ToolsList->value)->assertForbidden();

    User::factory()->create(['email' => 'unverified@example.test', 'email_verified_at' => null]);
    tantraChatMcp($this, tantraChatToken(['em' => 'unverified@example.test']), Method::ToolsList->value)->assertForbidden();
});

test('an account without an active subscription is refused like the API', function () {
    config(['trypost.self_hosted' => false]);
    $other = createApiTestToken();
    $other['user']->forceFill(['email' => 'unsubscribed@example.test', 'email_verified_at' => now()])->save();
    tantraChatMcp($this, tantraChatToken(['em' => 'unsubscribed@example.test']), Method::ToolsList->value)->assertStatus(402);
});

test('the existing MCP route still refuses chat tokens', function () {
    tantraChatMcp($this, tantraChatToken(['em' => 'chat.person@example.test']), Method::ToolsList->value, route: 'mcp.trypost')->assertUnauthorized();
});
