<?php

declare(strict_types=1);

use App\Tantra\Gateway\GatewaySignature;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

const GATEWAY_TEST_SECRET = 'test-gateway-secret-0123456789abcdef-xyz';
const GATEWAY_TEST_CLERK = 'user_GatewayTest0123456789';

/**
 * One gateway call, signed the way the CRM Worker signs it.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function gatewayCall(object $test, string $method, string $path, array $body = [], string $scopes = 'content:read,content:write,content:publish', array $headers = [], ?int $t = null, string $secret = GATEWAY_TEST_SECRET): TestResponse
{
    $raw = $body === [] ? '' : json_encode($body);
    $sig = GatewaySignature::sign($secret, 'k1', $t ?? time(), 'ws_crm_team', GATEWAY_TEST_CLERK, $scopes, $method, $path, $raw);

    return $test->call($method, $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TANTRA_SIGNATURE' => $sig,
        'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team',
        'HTTP_X_TANTRA_MEMBER' => GATEWAY_TEST_CLERK,
        'HTTP_X_TANTRA_SCOPES' => $scopes,
    ] + collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(), $raw);
}

beforeEach(function () {
    config(['tantra_gateway.enabled' => true, 'tantra_gateway.secrets' => ['k1' => GATEWAY_TEST_SECRET]]);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->user->forceFill(['clerk_user_id' => GATEWAY_TEST_CLERK])->save();
});

test('a signed read runs as the linked person in their workspace', function () {
    gatewayCall($this, 'GET', '/api/gateway/workspace')->assertOk();
    gatewayCall($this, 'GET', '/api/gateway/posts')->assertOk();
});

test('the gateway is off until it is enabled and has a secret', function () {
    config(['tantra_gateway.enabled' => false]);
    gatewayCall($this, 'GET', '/api/gateway/workspace')->assertNotFound();
    config(['tantra_gateway.enabled' => true, 'tantra_gateway.secrets' => []]);
    gatewayCall($this, 'GET', '/api/gateway/workspace')->assertNotFound();
});

test('refuses an unsigned, stale, forged or wrong body call', function () {
    $this->getJson('/api/gateway/workspace')->assertUnauthorized();
    gatewayCall($this, 'GET', '/api/gateway/workspace', t: time() - 3600)->assertUnauthorized();
    gatewayCall($this, 'GET', '/api/gateway/workspace', secret: 'another-secret-0123456789abcdef-0123456789')->assertUnauthorized();
    // Signed for one body, sent with another: the body hash is part of the signature.
    $sig = GatewaySignature::sign(GATEWAY_TEST_SECRET, 'k1', time(), 'ws_crm_team', GATEWAY_TEST_CLERK, 'content:write', 'POST', '/api/gateway/labels', '{"name":"a"}');
    $this->call('POST', '/api/gateway/labels', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TANTRA_SIGNATURE' => $sig,
        'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team', 'HTTP_X_TANTRA_MEMBER' => GATEWAY_TEST_CLERK, 'HTTP_X_TANTRA_SCOPES' => 'content:write',
    ], '{"name":"b"}')->assertUnauthorized();
});

test('the signed scopes decide what the call may do', function () {
    gatewayCall($this, 'POST', '/api/gateway/labels', ['name' => 'x', 'color' => '#ff0000'], scopes: 'content:read')->assertForbidden()->assertJsonPath('code', 'missing_scope');
    gatewayCall($this, 'GET', '/api/gateway/posts', scopes: 'content:write')->assertForbidden();
    gatewayCall($this, 'POST', '/api/gateway/repurposes/00000000-0000-0000-0000-000000000000/activate', scopes: 'content:read,content:write')->assertForbidden()->assertJsonPath('code', 'missing_scope');
});

test('a Tantra member with no linked engine account gets no access', function () {
    $this->user->forceFill(['clerk_user_id' => null])->save();
    gatewayCall($this, 'GET', '/api/gateway/workspace')->assertForbidden()->assertJsonPath('code', 'no_engine_access');
});

test('a person who cannot manage the team gets what the API would give them: no', function () {
    $other = createApiTestToken();
    subscribeAccount($other['user']->account);
    $other['workspace']->members()->attach($this->user->id, membershipPivot('member'));
    gatewayCall($this, 'GET', '/api/gateway/workspace', headers: ['X-Tantra-Content-Workspace' => (string) $other['workspace']->id])->assertForbidden();
});

test('engine API keys are not reachable through the gateway', function () {
    gatewayCall($this, 'GET', '/api/gateway/api-keys')->assertNotFound();
    gatewayCall($this, 'POST', '/api/gateway/api-keys', ['name' => 'x'])->assertNotFound();
});

test('the gateway is rate limited per person', function () {
    RateLimiter::clear('tantra-gateway:'.$this->user->id);
    for ($i = 0; $i < 3; $i++) {
        RateLimiter::hit('tantra-gateway:'.$this->user->id, 60);
    }
    expect(RateLimiter::attempts('tantra-gateway:'.$this->user->id))->toBe(3);
    for ($i = 0; $i < 600; $i++) {
        RateLimiter::hit('tantra-gateway:'.$this->user->id, 60);
    }
    gatewayCall($this, 'GET', '/api/gateway/workspace')->assertStatus(429);
});
