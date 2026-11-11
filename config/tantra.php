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


    // The Tantra LinkedIn page publishes through Zernio (PRD 10.4). One key for
    // the Account, only ever on the server. The webhook secret signs Zernio's
    // post.published / post.failed / post.partial events.
    'zernio' => [
        'key' => env('ZERNIO_API_KEY'),
        'url' => env('ZERNIO_API_URL', 'https://zernio.com/api/v1'),
        'webhook_secret' => env('ZERNIO_WEBHOOK_SECRET'),
        'max_accounts' => 2,
    ],

];
