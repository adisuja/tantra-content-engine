<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// tantra: in production the app is reached only through the cloudflared
// container on the compose network 172.30.0.0/24, so TRUSTED_PROXIES is set to
// that range (deploy/tantra/setup.sh). Requests from it must be read as the
// https request the visitor made, with the address Cloudflare appended.

const TANTRA_TUNNEL_SUBNET = '172.30.0.0/24';
const TANTRA_TUNNEL_CONNECTOR_IP = '172.30.0.5';
const TANTRA_VISITOR_IP = '203.0.113.9';
const TANTRA_FAKED_IP = '160.79.104.10';

beforeEach(function () {
    config(['trustedproxy.proxies' => TANTRA_TUNNEL_SUBNET]);

    Route::get('/_tantra-test/request', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'url' => url('/somewhere'),
    ]));
});

function tantraTunnelRequest(object $test, string $remoteAddress, string $proto): object
{
    return $test->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders([
            'X-Forwarded-For' => TANTRA_FAKED_IP.', '.TANTRA_VISITOR_IP,
            'X-Forwarded-Proto' => $proto,
            'X-Forwarded-Host' => 'content.usetantra.com',
            'X-Forwarded-Port' => $proto === 'https' ? '443' : '80',
        ])
        ->getJson('/_tantra-test/request')
        ->assertOk();
}

it('reads a tunnel request as the visitor\'s https request', function () {
    tantraTunnelRequest($this, TANTRA_TUNNEL_CONNECTOR_IP, 'https')->assertJson([
        'ip' => TANTRA_VISITOR_IP,
        'secure' => true,
        'url' => 'https://content.usetantra.com/somewhere',
    ]);
});

it('honours the forwarded scheme both ways', function () {
    tantraTunnelRequest($this, TANTRA_TUNNEL_CONNECTOR_IP, 'http')->assertJson(['secure' => false]);
});

it('ignores forwarded headers from outside the tunnel network', function () {
    tantraTunnelRequest($this, '198.51.100.7', 'https')->assertJson(['ip' => '198.51.100.7']);
});
