<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Manual\ManualTaskDue;
use App\Tantra\Notify\Notify;
use App\Tantra\Notify\SendToCrm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->ben = User::factory()->create(['name' => 'Ben Basil Tomy', 'email' => 'ben@example.test']);
    Mail::fake();
});

it('sends its own email when the CRM is not connected', function () {
    config(['tantra.crm.intake_key' => null]);
    Http::fake();

    Notify::send($this->ben, 'manual_task_due', ['task_id' => 't1'], new ManualTaskDue('t1', 'x_post', 'EA1', 'Post this'));

    Mail::assertQueued(ManualTaskDue::class);
    Http::assertNothingSent();
});

it('hands the event to the CRM, with the email as its message, and sends no email itself', function () {
    config(['tantra.crm.intake_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true, 'deduped' => false], 202)]);

    Notify::send($this->ben, 'manual_task_due', ['task_id' => 't1', 'due_at' => '2026-12-01T09:00:00Z'], new ManualTaskDue('t1', 'x_post', 'EA1', 'Post this'), 'Ben', 'https://content.test/tantra/tasks', 'Open the task');

    Http::assertSent(fn ($r) => $r->url() === 'https://crm.test/intake/engine'
        && $r->hasHeader('Authorization', 'Bearer k')
        && $r['event'] === 'engine.manual_task_due'
        && $r['recipient']['email'] === 'ben@example.test'
        && $r['data']['due_at'] === '2026-12-01T09:00:00Z'
        && filled($r['message']['subject']) && filled($r['message']['html'])
        && $r['message']['cta_url'] === 'https://content.test/tantra/tasks'
        && filled($r['id']));
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('falls back to its own email when the CRM keeps refusing', function () {
    $job = new SendToCrm('id-1', 'engine.manual_task_due', $this->ben->id, [], new ManualTaskDue('t1', 'x_post', 'EA1', 'Post this'), null, null, null);

    $job->failed(new RuntimeException('HTTP 500'));

    Mail::assertSent(ManualTaskDue::class, fn ($m) => $m->hasTo('ben@example.test'));
});

it('sends events that have no email of their own only through the CRM', function () {
    config(['tantra.crm.intake_key' => null]);
    Notify::send($this->ben, 'rebrand_ready', ['subject' => 'Your rebrand is ready']);
    Mail::assertNothingQueued();

    config(['tantra.crm.intake_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    Http::fake(['crm.test/*' => Http::response([], 202)]);
    Notify::send($this->ben, 'rebrand_ready', ['subject' => 'Your rebrand is ready']);
    Http::assertSent(fn ($r) => $r['message']['subject'] === 'Your rebrand is ready');
});
