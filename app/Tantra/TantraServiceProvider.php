<?php

declare(strict_types=1);

namespace App\Tantra;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Entry point for everything Tantra adds to the TryPost fork.
 *
 * Kept in its own namespace so upstream merges stay clean. Registered with a
 * single line in bootstrap/providers.php.
 */
class TantraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Our migrations live apart from upstream's, in database/migrations/tantra.
        $this->loadMigrationsFrom(database_path('migrations/tantra'));

        $this->trustTunnelProxy();
    }

    /**
     * The app is reached only through the Cloudflare Tunnel connector on the
     * private Docker network (no ports are published on the VM), so the
     * X-Forwarded-* headers it sends can be trusted. Without this Laravel sees
     * plain http and builds http:// URLs behind an https site.
     *
     * Set TANTRA_TRUST_PROXIES to an empty value to turn it off, or to a comma
     * separated list of addresses to narrow it.
     */
    private function trustTunnelProxy(): void
    {
        $proxies = (string) config('tantra.trust_proxies', '*');

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );
    }
}
