<?php

declare(strict_types=1);

// tantra: settings for everything the Tantra fork adds. Read with config('tantra.*').

return [

    // Proxies whose X-Forwarded-* headers are trusted. "*" is safe here because
    // the app is reachable only through the Cloudflare Tunnel connector on the
    // private Docker network. Empty turns it off; a comma separated list narrows it.
    'trust_proxies' => env('TANTRA_TRUST_PROXIES', '*'),

];
