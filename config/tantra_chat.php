<?php

declare(strict_types=1);

// tantra: Tantra Chat (the AI chat layer over the CRM, this engine and Tantra). Same secret as the CRM Worker
// (CHAT_SIGNING_SECRET) and the chat Worker at chat-api.usetantra.com. Unset = the /mcp/tantra-chat route is off.

return [
    'signing_secret' => env('TANTRA_CHAT_SIGNING_SECRET'),
];
