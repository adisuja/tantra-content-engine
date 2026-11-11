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
    // The pingers' bearer token for /tantra/health (PRD 8.5). Unset: the route answers 404.
    'health' => [
        'token' => env('TANTRA_HEALTH_TOKEN'),
    ],

    'zernio' => [
        'key' => env('ZERNIO_API_KEY'),
        'url' => env('ZERNIO_API_URL', 'https://zernio.com/api/v1'),
        'webhook_secret' => env('ZERNIO_WEBHOOK_SECRET'),
        'max_accounts' => 2,
    ],

    // Team engagement through Unipile V2 (PRD 10.5). Off until Adi turns it on.
    'unipile' => [
        'dsn' => env('UNIPILE_DSN'),
        'key' => env('UNIPILE_API_KEY'),
    ],
    'boost' => [
        'enabled' => (bool) env('TANTRA_BOOST_ENABLED', false),
        'window_min' => (int) env('TANTRA_BOOST_WINDOW_MIN', 60),
        'window_max' => (int) env('TANTRA_BOOST_WINDOW_MAX', 180),
        'reply_min' => 120,
        'reply_max' => 480,
        'daily_cap' => 15,
    ],

    // Every comment on a giveaway post gets a templated answer (PRD 10.5 step 6).
    'autoreply' => [
        'enabled' => (bool) env('TANTRA_AUTOREPLY_ENABLED', false),
        'days' => 14,
        'hours' => [9, 19],
        'per_run' => 40,
        'per_day' => 120,
        'gap' => [30, 90],
        'templates' => [
            'Thanks {first_name}, the {resource} is on its way to your DMs. If we are not connected yet, accept my request first.',
            'Sending it now, {first_name}. Check your DMs for the {resource}.',
            'Done, {first_name}: the {resource} is in your DMs. Accept my connection request if you see one.',
            '{first_name}, it is on its way. Look out for my message with the {resource}.',
        ],
    ],

];
