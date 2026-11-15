<?php

declare(strict_types=1);

// tantra C-10: the Tantra sign in is the only login. With TANTRA_EMBED_STANDALONE_REDIRECT on, the standalone sign
// in page sends people to Tantra's Content tab; inside the frame, for ?password=1 and for OAuth or connect flows it
// stays, and callbacks, webhooks and the embed pages are never touched.

const C10_SHELL = 'https://crm.usetantra.com/content';

beforeEach(function () {
    config([
        'app.url' => 'https://content.usetantra.com',
        'tantra.embed.enabled' => true,
        'tantra.embed.standalone_redirect' => true,
        'tantra.embed.shell_url' => C10_SHELL,
    ]);
});

it('sends the sign in page to Tantra when the flag is on', function () {
    $this->get('/login')->assertRedirect(C10_SHELL);
});

it('keeps the page the person was going to, inside Tantra', function () {
    $this->withSession(['url.intended' => 'https://content.usetantra.com/tantra/review?batch=9'])
        ->get('/login')
        ->assertRedirect(C10_SHELL.'?p='.rawurlencode('/tantra/review?batch=9'));
});

it('answers an Inertia visit with a full page location', function () {
    $this->get('/login', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', C10_SHELL);
});

it('shows the sign in page as before when the flag or the embed is off', function (array $config) {
    config($config);
    $this->get('/login')->assertOk();
})->with([
    'flag off' => [['tantra.embed.standalone_redirect' => false]],
    'embed off' => [['tantra.embed.enabled' => false]],
]);

it('never redirects inside the Tantra frame, so Tantra never opens inside itself', function () {
    $this->get('/login', ['Sec-Fetch-Dest' => 'iframe'])->assertOk();
});

it('keeps the password form on ?password=1', function () {
    $this->get('/login?password=1')->assertOk();
});

it('keeps the sign in page for OAuth, connect and other standalone flows', function (string $intended) {
    $this->withSession(['url.intended' => $intended])->get('/login')->assertOk();
})->with([
    'oauth' => 'https://content.usetantra.com/auth/google/callback?code=x',
    'connect' => 'https://content.usetantra.com/connect/linkedin',
    'accounts' => 'https://content.usetantra.com/accounts/x/callback',
    'embed' => 'https://content.usetantra.com/tantra/embed/signout',
    'set password' => 'https://content.usetantra.com/reset-password/abc',
]);

it('ignores an intended page on another host and opens Content', function () {
    $this->withSession(['url.intended' => 'https://evil.example.com/tantra/review'])
        ->get('/login')
        ->assertRedirect(C10_SHELL);
});

it('does not send a callback or webhook to Tantra', function (string $path) {
    $response = $this->get($path);
    expect((string) $response->headers->get('Location'))->not->toStartWith(C10_SHELL);
})->with([
    '/auth/google/callback',
    '/auth/github/callback',
    '/tantra/embed',
]);
