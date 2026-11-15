<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use App\Tantra\Notify\Notify;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * tantra: asks the Tantra app whether a workspace may publish (GET {crm}/intake/engine/billing?engine_workspace_id=, bearer
 * TANTRA_CRM_INTAKE_KEY, answer {enforced, plan, publishing_allowed, paused}). Used only behind TANTRA_BILLING_FROM_CRM; off by
 * default, and then the Engine's own plan table decides as before.
 *
 * Fails open: when the Tantra app cannot be reached, answers an error, answers something that is not a yes or a no, or is not
 * configured here, publishing is allowed. A billing outage must never stop customers' posts. A good answer is kept for a minute
 * and a failure for 30 seconds, so a down app never slows the scheduler to one slow request per post.
 */
final class CrmBilling
{
    private const GOOD_SECONDS = 60;

    private const FAILED_SECONDS = 30;

    public static function allows(string $workspaceId): bool
    {
        if (! Notify::crmConfigured()) {
            return true;
        }

        $cache = 'tantra:crm-billing:'.$workspaceId;
        $kept = Cache::get($cache);
        if (is_bool($kept)) {
            return $kept;
        }

        try {
            $response = Http::timeout(max(1, (int) config('tantra.billing.crm_timeout', 3)))->acceptJson()
                ->withToken((string) config('tantra.crm.intake_key'))
                ->get(rtrim((string) config('tantra.crm.url'), '/').'/intake/engine/billing', ['engine_workspace_id' => $workspaceId]);
            $allowed = $response->successful() ? $response->json('publishing_allowed') : null;
            if (is_bool($allowed)) {
                Cache::put($cache, $allowed, self::GOOD_SECONDS);

                return $allowed;
            }
            Log::warning('Tantra billing check gave no usable answer; publishing is allowed.', ['workspace_id' => $workspaceId, 'status' => $response->status()]);
        } catch (Throwable $e) {
            Log::warning('Tantra billing check failed; publishing is allowed.', ['workspace_id' => $workspaceId, 'error' => $e::class]);
        }

        Cache::put($cache, true, self::FAILED_SECONDS);

        return true;
    }
}
