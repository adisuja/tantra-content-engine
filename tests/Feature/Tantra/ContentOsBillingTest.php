<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Billing\BillingEvent;
use App\Tantra\Billing\BillingMail;
use App\Tantra\Billing\PublishingGate;
use App\Tantra\Billing\WebhookSignature;
use App\Tantra\Billing\WorkspacePlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

const COS_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const COS_PRODUCT = 'pdt_test_content_os';

beforeEach(function () {
    config(['tantra.billing.webhook_secret' => COS_SECRET, 'tantra.billing.product_id' => COS_PRODUCT, 'tantra.billing.api_key' => 'test_key', 'tantra.billing.environment' => 'test_mode', 'tantra.billing.grace_days' => 10]);
    Mail::fake();
    $this->owner = User::factory()->create(['email' => 'owner@example.test']);
    $this->ws = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Acme']);
    $this->ws->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->ws->id]);
});

/** Sends a Dodo event the way Dodo does: Standard Webhooks headers over the raw body. */
function dodo(mixed $test, string $type, array $data = [], ?string $id = null, ?int $at = null, ?string $secret = null): TestResponse
{
    $body = json_encode(['business_id' => 'bus_test', 'type' => $type, 'timestamp' => now()->toIso8601String(), 'data' => $data + [
        'subscription_id' => 'sub_1', 'product_id' => COS_PRODUCT, 'customer' => ['customer_id' => 'cus_1', 'email' => 'owner@example.test', 'name' => 'Owner'],
        'metadata' => ['workspace_id' => $test->ws->id, 'plan' => 'content_os'], 'recurring_pre_tax_amount' => 19900, 'currency' => 'USD',
        'next_billing_date' => now()->addMonth()->toIso8601String(),
    ]]);
    $id ??= 'msg_'.str()->random(12);
    $at ??= time();

    return $test->call('POST', '/tantra/billing/dodo/webhook', [], [], [], [
        'HTTP_WEBHOOK_ID' => $id, 'HTTP_WEBHOOK_TIMESTAMP' => (string) $at,
        'HTTP_WEBHOOK_SIGNATURE' => WebhookSignature::sign($secret ?? COS_SECRET, $id, (string) $at, $body), 'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function sentKinds(): array
{
    return Mail::queued(BillingMail::class)->map(fn (BillingMail $m): string => $m->kind.(isset($m->context['step']) ? ':'.$m->context['step'] : ''))->values()->all();
}

it('verifies Standard Webhooks signatures and refuses forged, stale and unsigned events', function () {
    dodo($this, 'subscription.active', secret: 'whsec_'.base64_encode('another-secret-entirely'))->assertStatus(401);
    dodo($this, 'subscription.active', at: time() - 600)->assertStatus(401);
    $this->postJson('/tantra/billing/dodo/webhook', ['type' => 'subscription.active'])->assertStatus(401);

    expect(BillingEvent::query()->count())->toBe(0)
        ->and(WorkspacePlan::query()->count())->toBe(0);
});

it('applies each event once, however often Dodo retries it', function () {
    dodo($this, 'subscription.active', id: 'msg_same')->assertOk()->assertJson(['outcome' => 'activated']);
    dodo($this, 'subscription.active', id: 'msg_same')->assertOk()->assertJson(['duplicate' => true]);

    expect(BillingEvent::query()->count())->toBe(1)
        ->and(sentKinds())->toBe(['welcome']);
});

it('ignores events for the account\'s other products', function () {
    dodo($this, 'subscription.active', ['product_id' => 'pdt_club', 'metadata' => [], 'subscription_id' => 'sub_club'])->assertOk()->assertJson(['outcome' => 'ignored_other_product']);

    expect(WorkspacePlan::query()->count())->toBe(0)->and(sentKinds())->toBe([]);
});

it('runs the whole cycle: welcome, receipt, heads-up, dunning on day 0, 3 and 7, pause, recovery, cancel, expiry and win-back', function () {
    config(['tantra.billing.enforce' => true]);
    $account = SocialAccount::factory()->create(['workspace_id' => $this->ws->id]);
    $post = Post::factory()->forAccount($account)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);
    expect(PublishingGate::allows($this->ws->id))->toBeFalse(); // never paid

    dodo($this, 'subscription.active')->assertJson(['outcome' => 'activated']);
    $plan = WorkspacePlan::query()->where('workspace_id', $this->ws->id)->sole();
    expect($plan->status)->toBe('active')->and($plan->dodo_customer_id)->toBe('cus_1')->and(PublishingGate::allows($this->ws->id))->toBeTrue();

    dodo($this, 'payment.succeeded')->assertJson(['outcome' => 'payment_recorded']);
    dodo($this, 'subscription.renewed', ['next_billing_date' => now()->addDays(3)->subHour()->toIso8601String()])->assertJson(['outcome' => 'renewed']);
    $this->artisan('tantra:billing-tick')->assertSuccessful();
    $this->artisan('tantra:billing-tick')->assertSuccessful();
    expect(sentKinds())->toBe(['welcome', 'receipt', 'renewal_notice']);

    dodo($this, 'payment.failed')->assertJson(['outcome' => 'on_hold']);
    dodo($this, 'subscription.on_hold')->assertJson(['outcome' => 'already_on_hold']);
    $this->travel(3)->days();
    $this->artisan('tantra:billing-tick');
    $this->travel(4)->days();
    $this->artisan('tantra:billing-tick');
    expect(PublishingGate::allows($this->ws->id))->toBeTrue(); // still in the grace period
    $this->travel(4)->days();
    $this->artisan('tantra:billing-tick');
    $this->artisan('tantra:billing-tick');
    expect(sentKinds())->toBe(['welcome', 'receipt', 'renewal_notice', 'payment_failed:0', 'payment_failed:1', 'payment_failed:2', 'paused'])
        ->and(PublishingGate::allows($this->ws->id))->toBeFalse();

    // Publishing pauses, the post stays scheduled with everything in it.
    $this->artisan('posts:process-scheduled');
    expect($post->refresh()->status)->toBe(PostStatus::Scheduled);

    dodo($this, 'subscription.renewed')->assertJson(['outcome' => 'recovered']);
    expect(PublishingGate::allows($this->ws->id))->toBeTrue();

    dodo($this, 'subscription.cancelled', ['next_billing_date' => now()->addDays(20)->toIso8601String()])->assertJson(['outcome' => 'cancelled']);
    expect(PublishingGate::allows($this->ws->id))->toBeTrue(); // paid period still running
    dodo($this, 'subscription.expired')->assertJson(['outcome' => 'expired']);
    expect(PublishingGate::allows($this->ws->id))->toBeFalse();
    $this->travel(15)->days();
    $this->artisan('tantra:billing-tick');
    $this->artisan('tantra:billing-tick');

    expect(array_slice(sentKinds(), 7))->toBe(['receipt', 'cancelled', 'expired', 'winback']);
});

it('never holds publishing while enforcement is off', function () {
    config(['tantra.billing.enforce' => false]);

    expect(PublishingGate::allows($this->ws->id))->toBeTrue();
});

it('opens a Dodo checkout with the workspace in the metadata, and the portal from the signed email link', function () {
    Http::fake([
        'test.dodopayments.com/checkouts' => Http::response(['session_id' => 'cks_1', 'checkout_url' => 'https://test.checkout.dodopayments.com/session/cks_1']),
        'test.dodopayments.com/customers/cus_1/customer-portal/session' => Http::response(['link' => 'https://customer.dodopayments.com/portal/abc']),
    ]);

    $this->actingAs($this->owner)->post(route('tantra.billing.checkout'))->assertRedirect('https://test.checkout.dodopayments.com/session/cks_1');
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/checkouts')
        && $r['product_cart'][0]['product_id'] === COS_PRODUCT
        && $r['metadata']['workspace_id'] === $this->ws->id
        && $r->hasHeader('Authorization', 'Bearer test_key'));

    WorkspacePlan::for($this->ws->id)->forceFill(['status' => 'on_hold', 'dodo_customer_id' => 'cus_1'])->save();
    $this->get(URL::temporarySignedRoute('tantra.billing.manage', now()->addDay(), ['workspace' => $this->ws->id]))->assertRedirect('https://customer.dodopayments.com/portal/abc');
    $this->get(route('tantra.billing.manage', ['workspace' => $this->ws->id]))->assertForbidden();
});

it('renders every billing email on the shared layout without dashes', function () {
    dodo($this, 'subscription.active');
    foreach (['welcome', 'receipt', 'renewal_notice', 'paused', 'cancelled', 'expired', 'winback'] as $kind) {
        $html = (new BillingMail($kind, $this->ws->id, ['amount' => '$199.00']))->render();
        expect($html)->toContain('tantra/email/logo.png')->not->toContain("\u{2014}")->not->toContain("\u{2013}");
    }
    foreach ([0, 1, 2] as $step) {
        expect((new BillingMail('payment_failed', $this->ws->id, ['step' => $step]))->render())->toContain('Update my card');
    }
});
