<?php

declare(strict_types=1);

use App\Enums\Mcp\Method;
use App\Models\User;
use App\Tantra\Chat\AuthenticateTantraChat;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

const TANTRA_CHAT_TEST_SECRET = 'test-chat-content-secret-0123456789abcdef';
const TANTRA_CHAT_TEST_CLERK = 'user_TestClerkId0123456789';

/**
 * An engine token exactly as the chat Worker mints it (HS256, iss tantra-chat, aud tantra-content, 5 minutes).
 *
 * @param  array<string, mixed>  $claims
 */
function tantraChatToken(array $claims = [], string $secret = TANTRA_CHAT_TEST_SECRET): string
{
    $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $h = $b64(json_encode(['alg' => 'HS256']));
    $p = $b64(json_encode(array_merge([
        'sub' => TANTRA_CHAT_TEST_CLERK, 'iss' => 'tantra-chat', 'aud' => 'tantra-content', 'iat' => time(), 'exp' => time() + 300,
    ], $claims)));
    $s = $b64(hash_hmac('sha256', "{$h}.{$p}", $secret, true));

    return "cs_{$h}.{$p}.{$s}";
}

/**
 * @param  array<string, mixed>  $params
 * @param  array<string, string>  $headers
 */
function tantraChatMcp(object $test, string $token, string $method, array $params = [], string $route = 'mcp.tantra-chat', array $headers = []): TestResponse
{
    return $test->withHeaders(array_merge([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ], $headers))->postJson(route($route), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ]);
}

beforeEach(function () {
    config(['tantra_chat.content_secret' => TANTRA_CHAT_TEST_SECRET]);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->user->forceFill(['clerk_user_id' => TANTRA_CHAT_TEST_CLERK])->save();
});

test('an engine token for a linked Clerk user uses the tools as that person in their workspace', function () {
    $token = tantraChatToken();
    $list = tantraChatMcp($this, $token, Method::ToolsList->value)->assertOk();
    expect(collect($list->json('result.tools'))->pluck('name'))->toContain('get-workspace-tool', 'list-posts-tool');

    $call = tantraChatMcp($this, $token, Method::ToolsCall->value, ['name' => 'get-workspace-tool', 'arguments' => (object) []])->assertOk();
    expect(json_encode($call->json('result')))->toContain((string) $this->workspace->id);

    tantraChatMcp($this, $token, Method::ToolsCall->value, ['name' => 'list-posts-tool', 'arguments' => (object) []], headers: ['X-Tantra-Workspace' => (string) $this->workspace->id])->assertOk();
});

test('the route is off until the secret is set', function () {
    config(['tantra_chat.content_secret' => null]);
    tantraChatMcp($this, tantraChatToken(), Method::ToolsList->value)->assertNotFound();
});

test('refuses the CRM chat token and every forged, long-lived or malformed token', function (callable $make) {
    tantraChatMcp($this, $make(), Method::ToolsList->value)->assertUnauthorized();
})->with([
    'CRM chat token (iss tantra-crm, aud tantra-chat)' => [fn () => tantraChatToken(['iss' => 'tantra-crm', 'aud' => 'tantra-chat', 'em' => 'a@example.test', 'exp' => time() + 7200])],
    'other secret' => [fn () => tantraChatToken([], 'another-secret-another-secret-xxxxxx')],
    'expired' => [fn () => tantraChatToken(['iat' => time() - 400, 'exp' => time() - 100])],
    'lives longer than five minutes' => [fn () => tantraChatToken(['exp' => time() + 3600])],
    'issued in the future' => [fn () => tantraChatToken(['iat' => time() + 600, 'exp' => time() + 900])],
    'wrong audience' => [fn () => tantraChatToken(['aud' => 'tantra-chat'])],
    'not a Clerk id' => [fn () => tantraChatToken(['sub' => 'dev:someone'])],
    'not a chat token' => [fn () => 'ck_team_api_key'],
    'tampered' => [fn () => substr(tantraChatToken(), 0, -3).'abc'],
]);

test('a Clerk user with no linked engine account gets no access, whatever the email', function () {
    User::factory()->create(['email' => 'same@example.test', 'email_verified_at' => now()]);
    tantraChatMcp($this, tantraChatToken(['sub' => 'user_SomeoneElse012345', 'em' => 'same@example.test']), Method::ToolsList->value)->assertForbidden();
});

test('a workspace switched since the chat started stops the chat', function () {
    tantraChatMcp($this, tantraChatToken(), Method::ToolsList->value, headers: ['X-Tantra-Workspace' => (string) $this->workspace->id])->assertOk();
    tantraChatMcp($this, tantraChatToken(), Method::ToolsList->value, headers: ['X-Tantra-Workspace' => 'some-other-workspace'])->assertStatus(409);
});

test('each person has their own rate limit', function () {
    for ($i = 0; $i < AuthenticateTantraChat::PER_MINUTE; $i++) {
        RateLimiter::hit("tantra-chat:{$this->user->id}", 60);
    }
    tantraChatMcp($this, tantraChatToken(), Method::ToolsList->value)->assertStatus(429);

    $other = createApiTestToken();
    subscribeAccount($other['user']->account);
    $other['user']->forceFill(['clerk_user_id' => 'user_OtherClerk0123456'])->save();
    tantraChatMcp($this, tantraChatToken(['sub' => 'user_OtherClerk0123456']), Method::ToolsList->value)->assertOk();
});

test('an account without an active subscription is refused like the API', function () {
    config(['trypost.self_hosted' => false]);
    $other = createApiTestToken();
    $other['user']->forceFill(['clerk_user_id' => 'user_Unsubscribed01234'])->save();
    tantraChatMcp($this, tantraChatToken(['sub' => 'user_Unsubscribed01234']), Method::ToolsList->value)->assertStatus(402);
});

test('the existing MCP route still refuses chat tokens', function () {
    tantraChatMcp($this, tantraChatToken(), Method::ToolsList->value, route: 'mcp.trypost')->assertUnauthorized();
});

test('tool calls other than reading the workspace need the pinned workspace', function () {
    tantraChatMcp($this, tantraChatToken(), Method::ToolsCall->value, ['name' => 'list-posts-tool', 'arguments' => (object) []])->assertStatus(409);
});

test('API key and webhook tools are refused on the chat route', function (string $tool) {
    tantraChatMcp($this, tantraChatToken(), Method::ToolsCall->value, ['name' => $tool, 'arguments' => (object) []], headers: ['X-Tantra-Workspace' => (string) $this->workspace->id])->assertForbidden();
})->with(['create-api-key-tool', 'list-api-keys-tool', 'get-webhook-tool', 'rotate-webhook-secret-tool', 'replay-webhook-log-tool']);
