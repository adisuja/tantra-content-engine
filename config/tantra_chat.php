<?php

declare(strict_types=1);

// tantra: Tantra Chat (the AI chat layer over the CRM, this engine and Tantra). The secret is shared only with the
// chat Worker at chat-api.usetantra.com, never with the CRM, so a CRM chat token cannot be used here and this
// secret cannot sign CRM sessions. Unset = the /mcp/tantra-chat route is off.

return [
    'content_secret' => env('TANTRA_CHAT_CONTENT_SECRET'),
];
