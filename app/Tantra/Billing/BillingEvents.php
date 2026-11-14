<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * tantra: applies one verified Dodo event to a workspace's Content OS plan and
 * sends the email it calls for. Dodo sends every product's events to every
 * endpoint, so anything that is not Content OS is acknowledged and left alone.
 * Returns what happened, stored with the event.
 */
final class BillingEvents
{
    /** @param array<string, mixed> $event */
    public static function apply(array $event): array
    {
        $type = (string) ($event['type'] ?? '');
        $data = (array) ($event['data'] ?? []);

        if (! self::isContentOs($data)) {
            return ['ignored_other_product', null];
        }

        $workspaceId = data_get($data, 'metadata.workspace_id')
            ?? WorkspacePlan::query()->where('dodo_subscription_id', (string) ($data['subscription_id'] ?? ''))->value('workspace_id');
        if ($workspaceId === null || ! Workspace::query()->whereKey($workspaceId)->exists()) {
            return ['no_workspace', null];
        }

        $plan = WorkspacePlan::for((string) $workspaceId);
        $plan->fill(array_filter([
            'dodo_customer_id' => data_get($data, 'customer.customer_id'),
            'dodo_subscription_id' => $data['subscription_id'] ?? null,
            'billing_email' => data_get($data, 'customer.email'),
        ]));
        $next = self::date($data['next_billing_date'] ?? null);
        $amount = self::money($data['recurring_pre_tax_amount'] ?? $data['total_amount'] ?? null, (string) ($data['currency'] ?? 'USD'));

        $outcome = match ($type) {
            'subscription.active' => self::activate($plan, $next, $amount),
            'subscription.renewed' => self::renewed($plan, $next, $amount),
            'payment.succeeded' => self::paid($plan, $amount),
            'subscription.on_hold', 'subscription.past_due', 'payment.failed' => self::failed($plan, $amount),
            'subscription.failed' => self::set($plan, ['status' => 'failed'], 'first_payment_failed'),
            'subscription.cancelled' => self::cancelled($plan, $next),
            'subscription.expired' => self::expired($plan),
            'subscription.updated', 'subscription.plan_changed' => self::set($plan, array_filter(['next_billing_at' => $next]), 'updated'),
            default => 'ignored_type',
        };

        return [$outcome, (string) $workspaceId];
    }

    /** @param array<string, mixed> $data */
    public static function isContentOs(array $data): bool
    {
        $product = (string) config('tantra.billing.product_id');

        return ($data['product_id'] ?? null) === $product
            || data_get($data, 'metadata.plan') === 'content_os'
            || collect((array) ($data['product_cart'] ?? []))->contains(fn ($line): bool => ($line['product_id'] ?? null) === $product)
            || (filled($data['subscription_id'] ?? null) && WorkspacePlan::query()->where('dodo_subscription_id', (string) $data['subscription_id'])->exists());
    }

    private static function activate(WorkspacePlan $plan, ?CarbonImmutable $next, string $amount): string
    {
        $first = $plan->activated_at === null;
        self::set($plan, ['status' => 'active', 'next_billing_at' => $next, 'period_ends_at' => $next, 'activated_at' => $plan->activated_at ?? now(),
            'payment_failed_at' => null, 'grace_ends_at' => null, 'dunning_step' => 0, 'paused_notice_at' => null, 'winback_sent_at' => null], 'activated');
        if ($first) {
            BillingMail::deliver($plan, 'welcome', ['amount' => $amount]);
        }

        return $first ? 'activated' : 'reactivated';
    }

    private static function renewed(WorkspacePlan $plan, ?CarbonImmutable $next, string $amount): string
    {
        $recovered = $plan->status === 'on_hold';
        self::set($plan, ['status' => 'active', 'next_billing_at' => $next, 'period_ends_at' => $next,
            'payment_failed_at' => null, 'grace_ends_at' => null, 'dunning_step' => 0, 'paused_notice_at' => null], 'renewed');
        BillingMail::deliver($plan, 'receipt', ['amount' => $amount, 'recovered' => $recovered]);

        return $recovered ? 'recovered' : 'renewed';
    }

    /** A charge went through. The receipt comes with subscription.renewed; this only ends a payment problem. */
    private static function paid(WorkspacePlan $plan, string $amount): string
    {
        if ($plan->status !== 'on_hold') {
            return 'payment_recorded';
        }
        self::set($plan, ['status' => 'active', 'payment_failed_at' => null, 'grace_ends_at' => null, 'dunning_step' => 0, 'paused_notice_at' => null], 'recovered');

        return 'recovered';
    }

    private static function failed(WorkspacePlan $plan, string $amount): string
    {
        if ($plan->activated_at === null) {
            return 'checkout_payment_failed'; // the buyer is still on the checkout page, which says so
        }
        if ($plan->status === 'on_hold') {
            return 'already_on_hold';
        }
        self::set($plan, ['status' => 'on_hold', 'payment_failed_at' => now(), 'grace_ends_at' => now()->addDays((int) config('tantra.billing.grace_days', 10)), 'dunning_step' => 0], 'on_hold');
        BillingMail::deliver($plan, 'payment_failed', ['step' => 0, 'amount' => $amount]);

        return 'on_hold';
    }

    private static function cancelled(WorkspacePlan $plan, ?CarbonImmutable $next): string
    {
        $until = $next ?? ($plan->period_ends_at ? CarbonImmutable::parse($plan->period_ends_at) : CarbonImmutable::now());
        self::set($plan, ['status' => 'cancelled', 'period_ends_at' => $until, 'next_billing_at' => null], 'cancelled');
        BillingMail::deliver($plan, 'cancelled', []);

        return 'cancelled';
    }

    private static function expired(WorkspacePlan $plan): string
    {
        self::set($plan, ['status' => 'expired', 'period_ends_at' => now(), 'next_billing_at' => null], 'expired');
        BillingMail::deliver($plan, 'expired', []);

        return 'expired';
    }

    /** @param array<string, mixed> $attributes */
    private static function set(WorkspacePlan $plan, array $attributes, string $outcome): string
    {
        $plan->forceFill($attributes)->save();

        return $outcome;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value) : null;
    }

    public static function money(mixed $minor, string $currency): string
    {
        if (! is_numeric($minor)) {
            return (string) config('tantra.billing.price_label');
        }

        return ($currency === 'USD' ? '$' : $currency.' ').number_format(((int) $minor) / 100, 2);
    }
}
