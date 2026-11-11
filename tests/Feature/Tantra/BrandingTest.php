<?php

declare(strict_types=1);

use App\Tantra\TantraServiceProvider;

// tantra: phpunit.xml turns branding off so upstream tests keep upstream's
// branding; these tests turn it on the way production runs.

function tantraBrandingOn(array $upstream = []): void
{
    config([
        'tantra.branding.enabled' => true,
        'app.name' => 'TryPost.it',
        'mail.from.name' => 'TryPost',
        'trypost.user_agent' => 'TryPost.it/1.0 (+https://trypost.it)',
        'trypost.legal.terms_url' => 'https://trypost.it/terms',
        'trypost.legal.privacy_url' => 'https://trypost.it/privacy',
        ...$upstream,
    ]);

    (new TantraServiceProvider(app()))->boot();
}

it('swaps upstream defaults for the Tantra name and user agent', function () {
    tantraBrandingOn();

    expect(config('app.name'))->toBe('Tantra Content Engine')
        ->and(config('mail.from.name'))->toBe('Tantra Content Engine')
        ->and(config('trypost.user_agent'))->toBe(config('tantra.branding.user_agent'))
        ->and(config('trypost.user_agent'))->not->toContain('trypost.it');
});

it('keeps values set explicitly in the environment', function () {
    tantraBrandingOn([
        'mail.from.name' => 'Growth Club',
        'trypost.legal.terms_url' => 'https://usetantra.com/terms',
    ]);

    expect(config('mail.from.name'))->toBe('Growth Club')
        ->and(config('trypost.legal.terms_url'))->toBe('https://usetantra.com/terms');
});

it('shares the brand and the source link with the login page, and drops TryPost legal links', function () {
    tantraBrandingOn();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tantra.branding', true)
            ->where('tantra.name', 'Tantra Content Engine')
            ->where('tantra.sourceUrl', 'https://github.com/adisuja/tantra-content-engine')
            ->where('legal.terms', '')
            ->where('legal.privacy', '')
        );
});

it('shares nothing when branding is off', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('tantra'));
});

it('ships the source code label in every locale', function (string $locale) {
    $strings = require lang_path("{$locale}/tantra.php");

    expect($strings['source_code'])->toBeString()->not->toBeEmpty();
})->with(array_map(fn ($path) => basename(dirname($path)), glob(lang_path('*/tantra.php'))));
