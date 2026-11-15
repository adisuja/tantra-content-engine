<?php

declare(strict_types=1);

// tantra C-15: framed inside Tantra, the page paints in the theme Tantra last sent (sessionStorage
// tantra.parentTheme, written by resources/js/lib/tantra/embed.ts), before any CSS loads.

it('reads Tantra\'s theme before the first paint, only when framed', function () {
    $html = (string) $this->get('/login?password=1')->assertOk()->getContent();

    expect($html)->toContain('window.self !== window.top')
        ->and($html)->toContain("sessionStorage.getItem('tantra.parentTheme')")
        ->and($html)->toContain("framed === 'light' || framed === 'dark'");
});
