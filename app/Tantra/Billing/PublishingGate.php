<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

/**
 * tantra: what a lapsed plan locks. Only publishing pauses: scheduled posts
 * stay scheduled, and every post, channel, idea and number stays. With
 * enforcement off (the default) nothing is ever held.
 *
 * With TANTRA_BILLING_FROM_CRM on, the Tantra app owns billing: it answers (CrmBilling, GET /intake/engine/billing), including whether
 * enforcement is on, and an unreachable app means publishing is allowed. Off (the default), the plan table here decides as before.
 */
final class PublishingGate
{
    public static function allows(?string $workspaceId): bool
    {
        if ($workspaceId === null) {
            return true;
        }

        if (config('tantra.billing.from_crm')) {
            return CrmBilling::allows($workspaceId);
        }

        if (! config('tantra.billing.enforce')) {
            return true;
        }

        $plan = WorkspacePlan::query()->where('workspace_id', $workspaceId)->first();

        return $plan !== null && $plan->allowsPublishing();
    }
}
