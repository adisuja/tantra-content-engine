<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use Illuminate\Console\Command;

/**
 * tantra: the time-based billing emails. Hourly: the renewal heads-up 3 days
 * before a charge, payment reminders on day 3 and day 7 of a failed charge,
 * the "publishing paused" email when the grace period ends, and one win-back
 * email 14 days after a plan ends.
 */
class BillingTickCommand extends Command
{
    protected $signature = 'tantra:billing-tick';

    protected $description = 'Send the time-based Content OS billing emails';

    public function handle(): int
    {
        $now = now();
        $sent = 0;

        WorkspacePlan::query()->where('status', 'active')->whereBetween('next_billing_at', [$now->copy()->addDays(2), $now->copy()->addDays(3)])->get()
            ->each(function (WorkspacePlan $plan) use (&$sent): void {
                if ($plan->renewal_notice_for?->equalTo($plan->next_billing_at)) {
                    return;
                }
                BillingMail::deliver($plan, 'renewal_notice', []);
                $plan->forceFill(['renewal_notice_for' => $plan->next_billing_at])->save();
                $sent++;
            });

        WorkspacePlan::query()->where('status', 'on_hold')->whereNotNull('payment_failed_at')->get()
            ->each(function (WorkspacePlan $plan) use ($now, &$sent): void {
                $days = $plan->payment_failed_at->diffInDays($now);
                if ($plan->grace_ends_at?->isPast()) {
                    if ($plan->paused_notice_at === null) {
                        BillingMail::deliver($plan, 'paused', []);
                        $plan->forceFill(['paused_notice_at' => $now])->save();
                        $sent++;
                    }

                    return;
                }
                foreach ([1 => 3, 2 => 7] as $step => $after) {
                    if ($plan->dunning_step < $step && $days >= $after) {
                        BillingMail::deliver($plan, 'payment_failed', ['step' => $step]);
                        $plan->forceFill(['dunning_step' => $step])->save();
                        $sent++;

                        return;
                    }
                }
            });

        WorkspacePlan::query()->whereIn('status', ['expired', 'cancelled'])->whereNull('winback_sent_at')
            ->where('period_ends_at', '<=', $now->copy()->subDays(14))->get()
            ->each(function (WorkspacePlan $plan) use ($now, &$sent): void {
                BillingMail::deliver($plan, 'winback', []);
                $plan->forceFill(['winback_sent_at' => $now])->save();
                $sent++;
            });

        $this->info("{$sent} billing email(s) sent");

        return self::SUCCESS;
    }
}
