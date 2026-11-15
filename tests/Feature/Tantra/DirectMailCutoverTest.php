<?php

declare(strict_types=1);

use App\Mail\WebhookPausedMail;
use App\Mail\WorkspaceInvite;
use App\Models\Invite;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Tantra\Notify\CoreEvents;
use App\Tantra\Notify\CrmMailFlags;
use App\Tantra\Notify\DirectMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// tantra (R3, 15 Nov): the 11 emails the Engine mailed itself become engine.* events once the main app owns them.

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    $this->owner = User::factory()->create(['name' => 'Ben Basil Tomy', 'email' => 'ben@example.test']);
});

function crmFlags(array $flags): void
{
    config(['tantra.crm.intake_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    Http::fake([
        'crm.test/intake/engine/mail-flags' => Http::response(['flags' => $flags, 'events' => ['webhook-paused' => 'engine.webhook_paused', 'workspace-invite' => 'engine.workspace_invite']]),
        'crm.test/intake/engine' => Http::response(['ok' => true], 202),
    ]);
}

it('mails exactly as before while the Engine owns the email, and sends no event', function () {
    crmFlags(['webhook-paused' => 'engine']);
    $webhook = Webhook::factory()->create(['endpoint' => 'https://hooks.example.test/x']);

    DirectMail::send($this->owner, new WebhookPausedMail($webhook));

    Mail::assertQueued(WebhookPausedMail::class, fn ($m) => $m->hasTo('ben@example.test'));
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/intake/engine'));
});

it('mails as before when the main app is not connected', function () {
    config(['tantra.crm.intake_key' => null]);
    Http::fake();
    $webhook = Webhook::factory()->create();

    DirectMail::send($this->owner, new WebhookPausedMail($webhook));

    Mail::assertQueued(WebhookPausedMail::class);
    Http::assertNothingSent();
});

it('sends only the event, marked for_mail with its data, once the main app owns the email', function () {
    crmFlags(['webhook-paused' => 'app']);
    $webhook = Webhook::factory()->create(['endpoint' => 'https://hooks.example.test/x']);

    DirectMail::send($this->owner, new WebhookPausedMail($webhook));

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/intake/engine')
        && $r['event'] === 'engine.webhook_paused'
        && $r['data']['for_mail'] === true
        && $r['data']['endpoint'] === 'https://hooks.example.test/x'
        && $r['message']['html'] === null
        && $r['recipient']['email'] === 'ben@example.test');
});

it('sends an invite to an address without an account as an event for that address', function () {
    crmFlags(['workspace-invite' => 'app']);
    $workspace = Workspace::factory()->create(['name' => 'GrowthClub']);
    $invite = Invite::factory()->create(['email' => 'kavya@example.test', 'workspaces' => [$workspace->id], 'invited_by' => $this->owner->id]);

    DirectMail::send('kavya@example.test', new WorkspaceInvite($invite), null, 'GrowthClub');

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/intake/engine')
        && $r['event'] === 'engine.workspace_invite'
        && $r['recipient']['email'] === 'kavya@example.test'
        && $r['recipient']['user_id'] === 'email:kavya@example.test'
        && $r['data']['workspace'] === 'GrowthClub'
        && $r['data']['by'] === 'Ben Basil Tomy'
        && $r['data']['for_mail'] === true);
});

it('tells the main app which emails it can hand over when it reads the flags', function () {
    crmFlags([]);

    CrmMailFlags::all();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/intake/engine/mail-flags')
        && $r->hasHeader('X-Tantra-Engine-Emits', CoreEvents::emitsHeader()));
    expect(CoreEvents::EMITS)->toContain('engine.post_published', 'engine.workspace_invite', 'engine.numbers_to_sign_off:quotes');
});

it('sends one header token per field, so no field is lost when the header is split on commas', function () {
    $tokens = explode(',', CoreEvents::emitsHeader());

    expect($tokens)->toContain('engine.manual_task_due:text', 'engine.manual_task_due:network', 'engine.media_missing:excerpt', 'engine.media_missing:due_label', 'engine.review_ready:posts', 'engine.post_published');
    foreach ($tokens as $token) {
        expect($token)->toMatch('/^engine\.[a-z0-9_]{1,60}(:[a-z0-9_]{1,120})?$/');
    }
});

it('reads the mail flags on the heartbeat every 5 minutes, even when no email went out', function () {
    crmFlags([]);
    $this->travelTo(now()->setTime(12, 5));

    $this->artisan('tantra:heartbeat')->assertSuccessful();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/intake/engine/mail-flags'));
});

it('does not read the mail flags on the other minutes', function () {
    crmFlags([]);
    $this->travelTo(now()->setTime(12, 6));

    $this->artisan('tantra:heartbeat')->assertSuccessful();

    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/intake/engine/mail-flags'));
});
