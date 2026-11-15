<?php

declare(strict_types=1);

test('tantra:routes-json lists the API routes and leaves the gateway copies out', function () {
    $this->artisan('tantra:routes-json')->assertSuccessful();
    $rows = collect(json_decode(\Illuminate\Support\Facades\Artisan::output(), true));
    expect($rows->pluck('path'))->toContain('/api/posts')->not->toContain('/api/gateway/posts');
    expect($rows->firstWhere('path', '/api/posts'))->toHaveKeys(['method', 'path', 'action', 'middleware']);
});

test('tantra:gateway-key-usage reads keys by last use', function () {
    $made = createApiTestToken();
    $this->artisan('tantra:gateway-key-usage', ['--json' => true])->assertSuccessful();
    expect(json_decode(\Illuminate\Support\Facades\Artisan::output(), true))->toBeArray();
});
