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

    // Every network is listed in Add channel; one is enabled once its developer
    // app keys are in (App\Tantra\Channels\ChannelApps). The test suite turns
    // this off so upstream tests keep their own *_ENABLED switches.
    'channel_apps' => [
        'enforce' => (bool) env('TANTRA_CHANNEL_APPS', true),
        // Old switch: company pages on before the owner saved a choice.
        'linkedin_page' => (bool) env('LINKEDIN_PAGE_ENABLED', false),
    ],

    // The Tantra LinkedIn page publishes through Zernio (PRD 10.4). One key for
    // the Account, only ever on the server. The webhook secret signs Zernio's
    // post.published / post.failed / post.partial events.
    // The pingers' bearer token for /tantra/health (PRD 8.5). Unset: the route answers 404.
    // Studio ops (PRD 25.4): the founding and development team, comma separated. Nobody is an admin by default.
    'admin_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('TANTRA_ADMIN_EMAILS', ''))))),

    'health' => [
        'token' => env('TANTRA_HEALTH_TOKEN'),
    ],

    // Format gaps (2026-11-14): features that need a paid plan or app review stay off until switched on.
    'x' => [
        // Quoting a post (quote_tweet_id) needs an X API plan that allows it (Enterprise).
        'quotes_enabled' => (bool) env('TANTRA_X_QUOTES_ENABLED', false),
    ],
    'meta' => [
        // Comment and reply scopes, asked for only once the Meta and Threads apps have them.
        'engagement_scopes' => (bool) env('TANTRA_META_ENGAGEMENT_SCOPES', false),
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

    // The Connectors tab. Endpoints in a private network are refused unless this is on.
    'ai' => [
        'allow_private_endpoints' => (bool) env('TANTRA_AI_ALLOW_PRIVATE_ENDPOINTS', false),
    ],

    // The Connectors tab: where the official Claude Code and Codex CLIs live in the image.
    'cli' => [
        'claude' => env('TANTRA_CLAUDE_CLI', 'claude'),
        'codex' => env('TANTRA_CODEX_CLI', 'codex'),
    ],

    // Sign in with the same Clerk logins as usetantra.com (2026-11-14). Off
    // until TANTRA_CLERK_LOGIN=true, so a deploy before the keys exist changes
    // nothing. The publishable key and the Frontend API are public; the secret
    // key is only used server side to read a person's verified email when the
    // session token does not carry it. App\Tantra\Auth.
    'clerk' => [
        'enabled' => (bool) env('TANTRA_CLERK_LOGIN', false),
        'publishable_key' => env('CLERK_PUBLISHABLE_KEY', 'pk_live_Y2xlcmsudXNldGFudHJhLmNvbSQ'),
        'frontend_api' => rtrim((string) env('CLERK_FRONTEND_API', 'https://clerk.usetantra.com'), '/'),
        'secret_key' => env('CLERK_SECRET_KEY'),
        'api_url' => rtrim((string) env('CLERK_API_URL', 'https://api.clerk.com/v1'), '/'),
        // The azp claim must be one of these origins (comma separated).
        'authorized_parties' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLERK_AUTHORIZED_PARTIES', 'https://content.usetantra.com'))))),
        // Seconds of clock skew allowed on exp and nbf.
        'leeway' => (int) env('CLERK_LEEWAY', 5),
        // Admin of every workspace on the main Account, granted again at each
        // sign in so new workspaces are covered (php artisan tantra:team-account).
        'team_emails' => array_values(array_filter(array_map(fn (string $email): string => strtolower(trim($email)), explode(',', (string) env('TANTRA_TEAM_EMAILS', 'team@growthclubpro.com'))))),
    ],

];
