<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('tantra:routes-json lists the API routes and leaves the gateway copies out', function () {
    expect(Artisan::call('tantra:routes-json'))->toBe(0);
    $rows = collect(json_decode(Artisan::output(), true));
    expect($rows->pluck('path'))->toContain('/api/posts')->not->toContain('/api/gateway/posts');
    expect($rows->firstWhere('path', '/api/posts'))->toHaveKeys(['method', 'path', 'action', 'middleware']);
});

test('tantra:gateway-key-usage reads keys by last use', function () {
    $made = createApiTestToken();
    expect(Artisan::call('tantra:gateway-key-usage', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true))->toBeArray();
});
