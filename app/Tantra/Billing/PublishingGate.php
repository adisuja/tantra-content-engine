<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

/**
 * tantra: what a lapsed plan locks. Only publishing pauses: scheduled posts
 * stay scheduled, and every post, channel, idea and number stays. With
 * enforcement off (the default) nothing is ever held.
 */
final class PublishingGate
{
    public static function allows(?string $workspaceId): bool
    {
        if (! config('tantra.billing.enforce') || $workspaceId === null) {
            return true;
        }

        $plan = WorkspacePlan::query()->where('workspace_id', $workspaceId)->first();

        return $plan !== null && $plan->allowsPublishing();
    }
}
