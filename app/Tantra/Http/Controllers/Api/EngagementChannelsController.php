<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Engagement\EngagementChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * tantra: POST /api/tantra/engagement/channels, server to server from the Tantra CRM (ENGAGEMENT_CHANNELS_CONTRACT.md).
 * Bearer ENGINE_CHANNELS_KEY, compared in constant time; a wrong, missing or unset key answers 401. The CRM workspace
 * id maps explicitly to Engine workspaces; an unmapped one gets an empty list (the `team` field is never used as a
 * fallback). Tokens only with include_tokens and purpose read or send. Never cached, never logged.
 */
class EngagementChannelsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) config('tantra.engagement_channels.key');
        if ($key === '' || ! hash_equals($key, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Invalid or missing key', 'code' => 'unauthenticated'], 401);
        }

        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:100'],
            'team' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', Rule::in(array_values(array_unique(EngagementChannels::PLATFORMS)))],
            'include_tokens' => ['sometimes', 'boolean'],
            'purpose' => ['nullable', Rule::in(['list', 'read', 'send'])],
        ]);

        $withTokens = (bool) ($data['include_tokens'] ?? false) && in_array($data['purpose'] ?? null, EngagementChannels::TOKEN_PURPOSES, true);
        $accounts = EngagementChannels::accounts(EngagementChannels::engineWorkspaces($data['workspace'] ?? null), $data['platform'] ?? null, $withTokens);

        return response()->json(['accounts' => $accounts])->header('Cache-Control', 'no-store, private');
    }
}
