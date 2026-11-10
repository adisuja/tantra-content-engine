<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// tantra: behind the Cloudflare Tunnel the app must honour X-Forwarded-Proto,
// or it builds http:// links on an https site.

beforeEach(function () {
    Route::get('/tantra-test/scheme', fn () => response()->json([
        'secure' => request()->isSecure(),
        'url' => url('/somewhere'),
    ]));
});

it('treats a tunnel request forwarded as https as secure', function () {
    $this->getJson('/tantra-test/scheme', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'content.usetantra.com',
        'X-Forwarded-Port' => '443',
    ])
        ->assertOk()
        ->assertJson([
            'secure' => true,
            'url' => 'https://content.usetantra.com/somewhere',
        ]);
});

it('stays on http when nothing is forwarded', function () {
    $this->getJson('/tantra-test/scheme')
        ->assertOk()
        ->assertJson(['secure' => false]);
});
