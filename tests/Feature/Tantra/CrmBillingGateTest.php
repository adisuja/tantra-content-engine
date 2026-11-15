<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Billing\CrmBilling;
use App\Tantra\Billing\PublishingGate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// tantra: with TANTRA_BILLING_FROM_CRM on, the Engine asks the Tantra app before publishing, and fails open.

beforeEach(function () {
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->ws = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    config(['tantra.crm.url' => 'https://crm.test', 'tantra.crm.intake_key' => 'intake-key', 'tantra.billing.from_crm' => true, 'tantra.billing.enforce' => false]);
});

/**
 * Fake the Tantra app's answer. A new fake on the same factory would not replace the one before it (Laravel keeps the first
 * stub that matches), so each call starts from a fresh factory. Attempts are counted here because a request that throws
 * is not recorded by Http::assertSentCount.
 */
function crmBillingAnswer(array|Throwable $answer, int $status = 200): void
{
    Http::swap(new HttpFactory);
    $GLOBALS['crm_billing_attempts'] = 0;
    Http::fake(function (Request $request) use ($answer, $status) {
        $GLOBALS['crm_billing_attempts']++;
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return Http::response($answer, $status);
    });
}

it('is off by default: the Engine plan table decides and the Tantra app is never asked', function () {
    config(['tantra.billing.from_crm' => false]);
    Http::fake();
    expect(PublishingGate::allows($this->ws->id))->toBeTrue(); // enforcement off: nothing is held
    config(['tantra.billing.enforce' => true]);
    expect(PublishingGate::allows($this->ws->id))->toBeFalse(); // never paid, as before
    Http::assertNothingSent();
});

it('asks the Tantra app by the Engine workspace id with the intake key, and follows its yes or no', function () {
    crmBillingAnswer(['enforced' => true, 'plan' => 'expired', 'publishing_allowed' => false, 'paused' => true]);
    expect(PublishingGate::allows($this->ws->id))->toBeFalse();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_starts_with($r->url(), 'https://crm.test/intake/engine/billing')
        && str_contains($r->url(), 'engine_workspace_id='.$this->ws->id) && $r->hasHeader('Authorization', 'Bearer intake-key'));

    Cache::flush();
    crmBillingAnswer(['enforced' => false, 'plan' => null, 'publishing_allowed' => true, 'paused' => false]);
    expect(PublishingGate::allows($this->ws->id))->toBeTrue();
});

it('lets the Tantra app decide even where the Engine plan table says no', function () {
    config(['tantra.billing.enforce' => true]); // the plan table would hold this workspace: it never paid
    crmBillingAnswer(['enforced' => false, 'plan' => null, 'publishing_allowed' => true, 'paused' => false]);
    expect(PublishingGate::allows($this->ws->id))->toBeTrue();
});

it('keeps a good answer for a minute and asks again after that', function () {
    crmBillingAnswer(['publishing_allowed' => false]);
    expect(PublishingGate::allows($this->ws->id))->toBeFalse()->and(PublishingGate::allows($this->ws->id))->toBeFalse();
    expect($GLOBALS['crm_billing_attempts'])->toBe(1);
    $this->travel(61)->seconds();
    crmBillingAnswer(['publishing_allowed' => true]);
    expect(PublishingGate::allows($this->ws->id))->toBeTrue();
    expect($GLOBALS['crm_billing_attempts'])->toBe(1); // the new fake starts a new count: one fresh ask
});

it('fails open when the Tantra app cannot be reached, answers an error, a refusal or nonsense', function (string $case) {
    Log::spy();
    match ($case) {
        'unreachable' => crmBillingAnswer(new ConnectionException('timeout')),
        'server error' => crmBillingAnswer(['error' => 'boom'], 500),
        'wrong key' => crmBillingAnswer(['error' => 'unauthorized'], 401),
        'not a yes or a no' => crmBillingAnswer(['publishing_allowed' => 'maybe']),
        'empty answer' => crmBillingAnswer([]),
    };
    expect(PublishingGate::allows($this->ws->id))->toBeTrue();
})->with(['unreachable', 'server error', 'wrong key', 'not a yes or a no', 'empty answer']);

it('does not hammer a down Tantra app: a failure is kept for 30 seconds, then asked again', function () {
    crmBillingAnswer(new ConnectionException('timeout'));
    expect(PublishingGate::allows($this->ws->id))->toBeTrue()->and(PublishingGate::allows($this->ws->id))->toBeTrue();
    expect($GLOBALS['crm_billing_attempts'])->toBe(1);
    $this->travel(31)->seconds();
    crmBillingAnswer(['publishing_allowed' => false]);
    expect(PublishingGate::allows($this->ws->id))->toBeFalse();
});

it('allows publishing when the Tantra app is not configured here, and for a post with no workspace', function () {
    config(['tantra.crm.intake_key' => null]);
    Http::fake();
    expect(CrmBilling::allows($this->ws->id))->toBeTrue()->and(PublishingGate::allows(null))->toBeTrue();
    Http::assertNothingSent();
});

it('holds a due post the Tantra app says may not publish, keeps it scheduled, and publishes it when the answer turns', function () {
    Bus::fake([PublishPost::class]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->forAccount($channel)->scheduled()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'content' => 'Due.', 'scheduled_at' => now()->subMinute()]);

    crmBillingAnswer(['enforced' => true, 'plan' => 'expired', 'publishing_allowed' => false, 'paused' => true]);
    $this->artisan('posts:process-scheduled');
    Bus::assertNotDispatched(PublishPost::class);
    expect($post->refresh()->status)->toBe(PostStatus::Scheduled);

    Cache::flush();
    crmBillingAnswer(['enforced' => true, 'plan' => 'active', 'publishing_allowed' => true, 'paused' => false]);
    $this->artisan('posts:process-scheduled');
    Bus::assertDispatched(PublishPost::class);
    expect($post->refresh()->status)->toBe(PostStatus::Publishing);
});

it('publishes a due post while the Tantra app is down (fail open)', function () {
    Bus::fake([PublishPost::class]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn]);
    Post::factory()->forAccount($channel)->scheduled()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'content' => 'Due.', 'scheduled_at' => now()->subMinute()]);
    crmBillingAnswer(new ConnectionException('down'));
    $this->artisan('posts:process-scheduled');
    Bus::assertDispatched(PublishPost::class);
});
