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
    // Load tests only (P3 capacity, section 10): every network publisher is replaced by a stub that waits latency_ms and answers a fake post
    // id. Nothing reaches a network. Refused in production whatever the switch says (App\Tantra\Ops\StubPublisher::enabled).
    'publish_stub' => [
        'enabled' => (bool) env('TANTRA_PUBLISH_STUB', false),
        'latency_ms' => (int) env('TANTRA_PUBLISH_STUB_LATENCY_MS', 800),
        // 0 to 100: the share of stubbed publishes that fail, to test the failure path under load.
        'fail_percent' => (int) env('TANTRA_PUBLISH_STUB_FAIL_PERCENT', 0),
    ],

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
        // One network at a time, comma separated (for example "threads"), while the others lack the permission.
        'engagement_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('TANTRA_META_ENGAGEMENT_NETWORKS', 'threads'))))),
    ],

    'zernio' => [
        'key' => env('ZERNIO_API_KEY'),
        'url' => env('ZERNIO_API_URL', 'https://zernio.com/api/v1'),
        'webhook_secret' => env('ZERNIO_WEBHOOK_SECRET'),
        'max_accounts' => 2,
    ],

    // Team engagement through Unipile V2 (PRD 10.5). Off until Adi turns it on.
    // Notifications go out through the Tantra CRM's automation (email, Slack, WhatsApp, SMS, AI calls), 2026-11-14.
    // Without a key, or if the CRM does not answer, the Engine sends its own email as before.
    // Content OS billing on Dodo Payments (App\Tantra\Billing). Keys only on the server
    // (tantra-ops sync-env). Off until TANTRA_BILLING_ENFORCE=true: then a workspace whose
    // plan has lapsed past its grace period stops publishing; its data stays.
    'billing' => [
        'api_key' => env('DODO_PAYMENTS_API_KEY'),
        'webhook_secret' => env('DODO_WEBHOOK_SECRET'),
        'environment' => env('DODO_ENV', 'test_mode'),
        'product_id' => env('DODO_CONTENT_OS_PRODUCT_ID', 'pdt_0Nrun85o9IVj9jlux7Nsf'),
        'price_label' => '$199 a month',
        'grace_days' => (int) env('TANTRA_BILLING_GRACE_DAYS', 10),
        'enforce' => (bool) env('TANTRA_BILLING_ENFORCE', false),
        // The Tantra app owns billing (tantra-crm docs/crm/emails/BILLING_GO_LIVE_STEPS.md step 9): before publishing, ask
        // GET {crm}/intake/engine/billing. Off by default; then the plan table here decides. Unreachable means allowed.
        'from_crm' => (bool) env('TANTRA_BILLING_FROM_CRM', false),
        'crm_timeout' => (int) env('TANTRA_BILLING_CRM_TIMEOUT', 3),
    ],

    // One Content app PRD 4.1 (critic B6): the engine UI shutoff. On, every engine screen sends people to the Tantra app (a GET goes to
    // the matching CRM page, any other UI request answers 410); OAuth, webhooks, the API, the gateway, MCP and signed links stay.
    // The lists are App\Tantra\UiOff\UiRoutes. Rollback: set it back to false.
    'ui_off' => [
        'enabled' => (bool) env('TANTRA_UI_OFF', false),
    ],

    'crm' => [
        'url' => env('TANTRA_CRM_URL', 'https://crm-api.usetantra.com'),
        'intake_key' => env('TANTRA_CRM_INTAKE_KEY'),
        // One Content app PRD 4.1: the Tantra app people use (not the Worker above). Engine emails and the account picker link here.
        'app_url' => rtrim((string) env('TANTRA_CRM_APP_URL', 'https://crm.usetantra.com'), '/'),
        // The account picker: /connect/{platform}/finish sends the person to Content > Channels in the CRM (?finish=<token>), which
        // lists and picks the accounts over the gateway. Always for a connection started from a CRM link; for every connection when on.
        'account_picker' => (bool) env('TANTRA_CRM_ACCOUNT_PICKER', false),
        // The CRM's /api/trk key: tracked short links and the traffic report. Server side only, never sent to the browser.
        'api_key' => env('TANTRA_CRM_API_KEY'),
        // Every AI call is sent to the CRM's AI cost ledger (A-05b, tantra:report-ai-usage). Same key as above.
        'ai_usage' => (bool) env('TANTRA_CRM_AI_USAGE', true),
        // The Engagement tab (PRD Engagement, phase 4): a view and editor on the CRM's /api/engagement routes, which run
        // the follow-ups. Same key as above, server side only. The main Account's workspaces map to the key's CRM workspace.
        'engagement' => [
            'enabled' => (bool) env('TANTRA_CRM_ENGAGEMENT', true),
            'timeout' => (int) env('TANTRA_CRM_ENGAGEMENT_TIMEOUT', 20),
            // Every published post is sent to Engagement as engine.post_published (One Tantra R1), to become a watched post.
            'posts' => (bool) env('TANTRA_CRM_ENGAGEMENT_POSTS', true),
        ],
        // Speed P3 (tantra-crm PRD_2026_11_21_SPEED_AND_STABILITY section 10, B2 and B3): every post, review, channel and metrics change is sent
        // to the CRM intake as engine.content_changed, after commit, for the team's Content mirror. Only workspaces mapped to a Tantra team
        // (tantra_workspace_links, else ENGINE_CHANNELS_WORKSPACES) send anything. Its own low priority queue and worker, never the publishing pool.
        'mirror' => [
            'enabled' => (bool) env('TANTRA_CRM_MIRROR', true),
            'queue' => (string) env('TANTRA_CRM_MIRROR_QUEUE', 'tantra-mirror'),
            'processes' => (int) env('TANTRA_CRM_MIRROR_PROCESSES', 1),
            'timeout' => (int) env('TANTRA_CRM_MIRROR_TIMEOUT', 10),
            // Metrics arrive in bursts (one row per post per day): one metrics.read per workspace per this many seconds.
            'metrics_debounce' => (int) env('TANTRA_CRM_MIRROR_METRICS_DEBOUNCE', 60),
        ],
        // Every link in content becomes a CRM short link for its piece and person (card 29 context, 2026-11-14).
        'links' => [
            'enabled' => (bool) env('TANTRA_CRM_LINKS', true),
            'campaign_id' => env('TANTRA_CRM_LINKS_CAMPAIGN', 'cmp_f427ef8e47514047be1d'),
            // Known campaign channels per platform; others are added to the campaign on first use.
            'channels' => ['linkedin' => 'chn_c1118abc4dc74c06a10b'],
            'short_host' => 'go.usetantra.com',
            'utm_campaign' => 'enterprise-webinar',
            // Organic traffic lands here. The cold calendar domain and the old brand fold into it.
            'organic' => 'https://jointantra.com/',
            'organic_hosts' => ['jointantra.com', 'www.jointantra.com', 'webinar.sellwithtantra.com'],
            'retired_hosts' => ['growthclub.org', 'www.growthclub.org'],
            // Never rewritten: the short links themselves and the app's own hosted files and previews.
            'skip_hosts' => ['go.usetantra.com', 'content.usetantra.com', 'linkedin.com', 'www.linkedin.com', 'lnkd.in'],
        ],
        // Each imported giveaway (DM, connection note, email, resource link) goes to the CRM's Engagement asset
        // library as its defaults (App\Tantra\Review\Jobs\SyncEngagementAssets). Main Account only, needs api_key.
        'engagement_assets' => (bool) env('TANTRA_CRM_ENGAGEMENT_ASSETS', true),
    ],

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

    // The Engine inside the Tantra CRM's Content tab (PRD 2026-11-15). Off
    // until TANTRA_EMBED=true: GET tantra/embed and POST tantra/embed/session
    // answer 404 until then. The parents are the origins allowed to frame the
    // Engine and to hand it a Clerk session token (comma separated), and the
    // frame-ancestors header lists them. App\Tantra\Embed.
    'embed' => [
        'enabled' => (bool) env('TANTRA_EMBED', false),
        'parents' => array_values(array_filter(array_map(fn (string $origin): string => rtrim(trim($origin), '/'), explode(',', (string) env('TANTRA_EMBED_PARENTS', ''))))),
        // Content phase 2 (One Tantra S-12). Each is off until set, so nothing changes on deploy.
        // A Tantra teammate with no Engine login gets one on their first Content visit: created from the verified
        // Clerk email and added to the Tantra team's Engine workspace, when that email is a member of the Tantra
        // workspace the CRM key (TANTRA_CRM_API_KEY) belongs to. Workspace: an id or a name in the main Account.
        'provision' => [
            'enabled' => (bool) env('TANTRA_EMBED_PROVISION', false),
            'workspace' => env('TANTRA_EMBED_PROVISION_WORKSPACE', 'Tantra'),
        ],
        // Links in Engine emails open inside Tantra (https://crm.usetantra.com/content?p=<path>) instead of the
        // standalone app. OAuth, sign in, set password and signed links are never rewritten.
        'email_links' => (bool) env('TANTRA_EMBED_EMAIL_LINKS', false),
        'shell_url' => rtrim((string) env('TANTRA_EMBED_SHELL_URL', 'https://crm.usetantra.com/content'), '/'),
        // C-10: the standalone sign in page sends people to Tantra's Content tab, so the Tantra sign in is the only
        // login. Never inside the Tantra frame, never for ?password=1, and never when the person was on their way to a
        // standalone path (OAuth, connect, set password). Callbacks, webhooks and public pages are not touched.
        'standalone_redirect' => (bool) env('TANTRA_EMBED_STANDALONE_REDIRECT', false),
    ],

    // Engagement channels (tantra-crm docs/crm/ENGAGEMENT_CHANNELS_CONTRACT.md): the CRM fetches each team's connected
    // accounts server to server, and gets the Telegram bot's private-chat updates. Shared key, compared in constant time.
    'engagement_channels' => [
        'key' => env('ENGINE_CHANNELS_KEY'),
        // CRM workspace id => Engine workspace ids, mapped explicitly: "ws_abc=uuid1|uuid2,ws_def=uuid3". Unmapped => empty list.
        'workspaces' => env('ENGINE_CHANNELS_WORKSPACES', ''),
        'per_minute' => (int) env('ENGINE_CHANNELS_PER_MINUTE', 60),
        // The Tantra Worker (crm-api), not the app at crm.usetantra.com, which serves no hooks.
        'telegram_forward_url' => env('TELEGRAM_ENGAGEMENT_URL', 'https://crm-api.usetantra.com/hooks/engagement/telegram'),
        // Contract v2 (S-06): one secret per CRM workspace, made in Tantra (POST /api/engagement/hook-secrets/rotate shows
        // it once) and pasted here as "ws_abc=hk_kid1:secret1,ws_def=hk_kid2:secret2". A workspace with no entry gets
        // nothing forwarded, so this stays inert until its secret is set.
        'telegram_secrets' => env('TELEGRAM_ENGAGEMENT_SECRETS', ''),
    ],

    // Create as a chat (CR-06): the Tantra CRM builds the finished post itself and sends it on POST
    // /api/gateway/tantra/create (and PUT /api/gateway/tantra/review/{item}) in `built`. Its media is downloaded only
    // from these hosts, https only, with no redirect followed (exact names or "*.suffix", comma separated). The CRM
    // serves its R2 files from its own Worker origin (crm-api.usetantra.com). Empty: no built media is taken.
    'built' => [
        'media_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TANTRA_BUILT_MEDIA_HOSTS', 'crm-api.usetantra.com'))))),
        'max_pages' => (int) env('TANTRA_BUILT_MAX_PAGES', 20),
        'max_file_mb' => (int) env('TANTRA_BUILT_MAX_FILE_MB', 100),
        'max_total_mb' => (int) env('TANTRA_BUILT_MAX_TOTAL_MB', 300),
    ],

];
