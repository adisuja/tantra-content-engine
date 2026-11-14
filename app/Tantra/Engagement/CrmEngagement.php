<?php

declare(strict_types=1);

namespace App\Tantra\Engagement;

use App\Models\User;
use App\Tantra\Agent\Admin;
use App\Tantra\Auth\TeamAccess;
use App\Tantra\Links\CrmTracking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * tantra: the Tantra CRM's Engagement API (crm-api.usetantra.com, /api/engagement/* and the sequence routes), the
 * work behind the Engagement tab. The CRM runs the pipeline, the stage sequences, enrichment and sending (PRD
 * Engagement, Adi's decision of 14 Nov 2026); the Engine shows and edits them.
 *
 * Auth and workspace: the CRM key (TANTRA_CRM_API_KEY, a `ck_` team key) acts as the CRM teammate who made it, in
 * that teammate's CRM workspace. The Engine's main Account (the Tantra team) maps to that workspace. Other Accounts
 * have no CRM workspace yet: the CRM creates a workspace only at a person's first Clerk sign in and has no route that
 * provisions one for a service, so they see a "not connected" state. The key never reaches the browser.
 */
class CrmEngagement
{
    /**
     * Whether this person's Engine has a CRM workspace, and why not when it has none.
     *
     * @return array{connected: bool, reason: string|null}
     */
    public static function connection(User $user): array
    {
        $reason = match (true) {
            ! (bool) config('tantra.crm.engagement.enabled', true) => 'off',
            ! CrmTracking::configured() => 'no_key',
            $user->account_id === null || $user->account_id !== TeamAccess::mainAccount()?->id => 'no_workspace',
            default => null,
        };

        return ['connected' => $reason === null, 'reason' => $reason];
    }

    /** Owner-level actions (approve and start, team roster, sequences, moving people): the Account owner, the team login and admins. */
    public static function canManage(User $user): bool
    {
        return $user->account?->owner_id === $user->id
            || $user->getAttribute('tantra_team_access_at') !== null
            || Admin::is($user);
    }

    /**
     * One call to the CRM. Throws CrmUnreachable when the CRM does not answer or fails on its side, and CrmRefused
     * (with the CRM's own words) when it says no.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $query = array_filter($query, fn (mixed $v): bool => $v !== null && $v !== '');
        $url = '/api/'.ltrim($path, '/');

        try {
            $request = Http::baseUrl(rtrim((string) config('tantra.crm.url'), '/'))
                ->withToken((string) config('tantra.crm.api_key'))
                ->acceptJson()
                ->timeout((int) config('tantra.crm.engagement.timeout', 20));
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $query),
                'POST' => $request->post($url.self::qs($query), $body ?? []),
                'PATCH' => $request->patch($url.self::qs($query), $body ?? []),
                'PUT' => $request->put($url.self::qs($query), $body ?? []),
                'DELETE' => $request->delete($url.self::qs($query), $body ?? []),
                default => throw new \InvalidArgumentException("Unsupported method {$method}"),
            };
        } catch (ConnectionException $e) {
            throw new CrmUnreachable($e->getMessage(), 0, $e);
        }

        return $this->ok($response);
    }

    /** @return array<string, mixed> */
    private function ok(Response $response): array
    {
        if ($response->serverError()) {
            throw new CrmUnreachable("The CRM failed (HTTP {$response->status()}).");
        }
        if (! $response->successful()) {
            $message = (string) ($response->json('error') ?? $response->json('message') ?? '');
            throw new CrmRefused($response->status(), $message, $response->json('code') !== null ? (string) $response->json('code') : null);
        }

        return (array) ($response->json() ?? []);
    }

    /** @param array<string, mixed> $query */
    private static function qs(array $query): string
    {
        return $query === [] ? '' : '?'.http_build_query($query);
    }
}
