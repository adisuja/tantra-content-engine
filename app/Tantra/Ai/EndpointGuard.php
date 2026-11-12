<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use App\Services\Http\HostResolver;
use RuntimeException;

/**
 * tantra: a connector's endpoint must be a public https (or http) host. A
 * private, loopback, link-local or cloud-metadata address is refused, so a
 * saved key can never be sent into the server's own network. Self-hosted
 * setups that run OmniRoute next to the engine opt in with
 * TANTRA_AI_ALLOW_PRIVATE_ENDPOINTS=true (or trypost.security.allow_private_network).
 */
final class EndpointGuard
{
    public static function check(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('The endpoint must be an http or https address.');
        }

        if ((bool) config('tantra.ai.allow_private_endpoints') || (bool) config('trypost.security.allow_private_network')) {
            return;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : app(HostResolver::class)->addresses($host);
        if ($addresses === []) {
            throw new RuntimeException("The endpoint host {$host} could not be found.");
        }

        foreach ($addresses as $address) {
            $global = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE);
            if ($global === false) {
                throw new RuntimeException('That endpoint points into a private network, which the engine does not call.');
            }
        }
    }
}
