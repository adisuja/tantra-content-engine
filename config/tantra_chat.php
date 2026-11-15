<?php

declare(strict_types=1);

// tantra: Tantra Chat (the AI chat layer over the CRM, this engine and Tantra). The secret is shared only with the
// chat Worker at chat-api.usetantra.com, never with the CRM: a CRM chat token cannot be used here directly and this
// secret cannot sign CRM sessions. The Worker still exchanges a valid CRM chat session for an engine token, so the
// CRM's own signing secret remains the root of trust for who is chatting. Unset = the /mcp/tantra-chat route is off.

return [
    'content_secret' => env('TANTRA_CHAT_CONTENT_SECRET'),
];
