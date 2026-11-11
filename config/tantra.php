<?php

declare(strict_types=1);

// tantra: settings for everything the Tantra fork adds. Read with config('tantra.*').

return [

    // Tantra name, logo and links in place of TryPost's, and an AGPL "Source
    // code" link. Upstream's test suite runs with this off (phpunit.xml) so
    // it keeps testing upstream's own branding.
    'branding' => [
        'enabled' => (bool) env('TANTRA_BRANDING', true),
        'name' => 'Tantra Content Engine',
        'source_url' => env('TANTRA_SOURCE_URL', 'https://github.com/adisuja/tantra-content-engine'),
        'user_agent' => 'TantraContentEngine/1.0 (+https://github.com/adisuja/tantra-content-engine)',
    ],

    // In production, fold upstream's Horizon supervisors into one pool of a
    // few workers (App\Tantra\Ops\LeanHorizon), sized for a small team on a
    // 2 GB server. Set TANTRA_LEAN_WORKERS=false to use upstream's sizing.
    'lean_workers' => [
        'enabled' => (bool) env('TANTRA_LEAN_WORKERS', true),
        'processes' => (int) env('TANTRA_LEAN_WORKER_PROCESSES', 3),
    ],

];
