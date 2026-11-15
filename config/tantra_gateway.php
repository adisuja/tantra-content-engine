<?php

declare(strict_types=1);

// tantra: the one API gateway (docs/crm/PRD_ONE_API_AND_MCP.md section 9). The Tantra CRM Worker signs every call it proxies
// to /api/gateway/* with a shared secret. TANTRA_GATEWAY_SECRETS is "kid:secret,kid2:secret2" (two while one is rotated out;
// each secret at least 32 characters). Off by default: with TANTRA_GATEWAY unset or false every gateway route answers 404.

$secrets = [];
foreach (explode(',', (string) env('TANTRA_GATEWAY_SECRETS', '')) as $pair) {
    [$kid, $secret] = array_pad(explode(':', trim($pair), 2), 2, '');
    if ($kid !== '' && strlen($secret) >= 32) {
        $secrets[$kid] = $secret;
    }
}

return [
    'enabled' => filter_var(env('TANTRA_GATEWAY', false), FILTER_VALIDATE_BOOLEAN),
    'secrets' => $secrets,
];
