<?php

declare(strict_types=1);

use App\Tantra\Gateway\GatewaySignature;
use Illuminate\Testing\TestResponse;

// tantra: One Content app PRD (lane E1). A signed gateway call as one Tantra member, with extra headers (If-Match).

const ONE_GW_SECRET = 'one-content-gateway-secret-0123456789abcdef';
const ONE_GW_CLERK = 'user_OneContentOwner0123456';

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function oneGatewayCall(object $test, string $method, string $path, array $body = [], array $headers = [], string $scopes = 'content:read,content:write,content:publish', string $member = ONE_GW_CLERK): TestResponse
{
    $raw = $body === [] ? '' : json_encode($body);
    $sig = GatewaySignature::sign(ONE_GW_SECRET, 'k1', time(), 'ws_crm_team', $member, $scopes, $method, $path, $raw);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TANTRA_SIGNATURE' => $sig,
        'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team',
        'HTTP_X_TANTRA_MEMBER' => $member,
        'HTTP_X_TANTRA_SCOPES' => $scopes,
    ];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call($method, $path, [], [], [], $server, $raw);
}

function oneGatewaySetUp(object $test): void
{
    config(['tantra_gateway.enabled' => true, 'tantra_gateway.secrets' => ['k1' => ONE_GW_SECRET]]);
}
