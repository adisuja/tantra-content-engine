<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Agent\AgentRunner;
use App\Tantra\Agent\ClaudeAccount;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->adi->forceFill(['email' => 'adi@example.test', 'email_verified_at' => now()])->save();
    $this->token = $result['plain_token'];
    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id, 'email_verified_at' => now()]);
    config(['tantra.admin_emails' => ['adi@example.test']]);
});

function runnerToken(?string $accountId = null): array
{
    return AgentRunner::issue('Laptop', 'local', $accountId);
}

it('shows Studio ops to admins only, and only with a verified email', function () {
    $this->actingAs($this->adi)->get('/tantra/ops')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('tantra/ops/Index'));
    $this->actingAs($this->ben)->get('/tantra/ops')->assertNotFound();

    // A listed address nobody has verified does not make anyone an admin.
    config(['tantra.admin_emails' => ['adi@example.test', 'boss@example.test']]);
    $this->ben->forceFill(['email' => 'BOSS@example.test', 'email_verified_at' => null])->save();
    $this->actingAs($this->ben)->get('/tantra/ops')->assertNotFound();
});

it('starts a cloud run for a job an admin queues', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['session_id' => 'session_123'], 200)]);
    ClaudeAccount::query()->create(['name' => 'Adi cloud', 'kind' => 'cloud_task', 'fire_url' => 'https://api.anthropic.com/v1/claude_code/routines/trig_1/fire', 'secret' => 'tok']);

    $job = AgentJobs::enqueue('ping', ['say' => 'pong'], $this->adi);

    expect($job->status)->toBe(AgentJob::DISPATCHED)->and($job->session_url)->toBe('https://claude.ai/code/session_123');
    Http::assertSent(fn ($r) => $r->hasHeader('anthropic-beta', 'experimental-cc-routine-2026-04-01')
        && str_contains((string) $r['text'], $job->id));
});

it('holds a job someone else starts until an admin runs it', function () {
    $job = AgentJobs::enqueue('ping', [], $this->ben);
    expect($job->status)->toBe(AgentJob::AWAITING_ADMIN)->and($job->toOutcome()['status'])->toBe('waiting');

    [, $token] = runnerToken();
    $this->withToken($token)->postJson('/api/tantra/agent/claim')->assertNoContent();

    $this->actingAs($this->adi)->postJson("/tantra/ops/jobs/{$job->id}/run")->assertOk();
    expect($job->refresh()->status)->toBe(AgentJob::QUEUED);
});

it('runs a ping job round trip through a runner, once', function () {
    $job = AgentJobs::enqueue('ping', ['say' => 'pong'], $this->adi);
    [, $token] = runnerToken();

    $claim = $this->withToken($token)->postJson('/api/tantra/agent/claim')->assertOk()->json('data');
    expect($claim['id'])->toBe($job->id)->and($claim['input'])->toBe(['say' => 'pong']);
    $claimToken = $claim['claim_token'];

    // Without the claim token, nothing.
    $this->withToken($token)->getJson("/api/tantra/agent/jobs/{$job->id}")->assertStatus(409);

    $this->withToken($token)->withHeader('X-Claim-Token', $claimToken)
        ->postJson("/api/tantra/agent/jobs/{$job->id}/log", ['line' => 'Thinking.'])->assertOk();
    $this->withToken($token)->withHeader('X-Claim-Token', $claimToken)
        ->postJson("/api/tantra/agent/jobs/{$job->id}/complete", ['result' => ['runner' => 'Laptop']])
        ->assertOk()->assertJsonPath('summary', 'Pong from Laptop.');
    // The same result again changes nothing.
    $this->withToken($token)->withHeader('X-Claim-Token', $claimToken)
        ->postJson("/api/tantra/agent/jobs/{$job->id}/complete", ['result' => ['runner' => 'Other']])
        ->assertOk()->assertJsonPath('summary', 'Pong from Laptop.');

    expect($job->refresh())->status->toBe(AgentJob::DONE)->summary->toBe('Pong from Laptop.');
    expect(collect($job->log)->pluck('line'))->toContain('Thinking.');
});

it('gives a job to one run only', function () {
    $job = AgentJobs::enqueue('ping', [], $this->adi);
    [, $a] = runnerToken();
    [, $b] = runnerToken();

    $this->withToken($a)->postJson('/api/tantra/agent/claim', ['job_id' => $job->id])->assertOk();
    $this->withToken($b)->postJson('/api/tantra/agent/claim', ['job_id' => $job->id])->assertStatus(409);
    $this->withToken($b)->withHeader('X-Claim-Token', 'x')->getJson("/api/tantra/agent/jobs/{$job->id}")->assertNotFound();
});

it('refuses a result for a cancelled job and an unknown runner token', function () {
    $job = AgentJobs::enqueue('ping', [], $this->adi);
    [, $token] = runnerToken();
    $claimToken = $this->withToken($token)->postJson('/api/tantra/agent/claim')->json('data.claim_token');
    AgentJobs::cancel($job->refresh());

    $this->withToken($token)->withHeader('X-Claim-Token', $claimToken)
        ->postJson("/api/tantra/agent/jobs/{$job->id}/complete", ['result' => []])->assertStatus(422);
    $this->withToken('tar_nope')->postJson('/api/tantra/agent/claim')->assertUnauthorized();
});

it('requeues a quiet run, then fails it, and purges old inputs', function () {
    $job = AgentJobs::enqueue('ping', ['big' => 'input'], $this->adi);
    [$runner] = runnerToken();
    AgentJobs::claim($runner);
    $job->refresh()->forceFill(['heartbeat_at' => now()->subHour()])->save();

    AgentJobs::tick();
    expect($job->refresh()->status)->toBe(AgentJob::QUEUED);

    AgentJobs::claim($runner);
    $job->refresh()->forceFill(['heartbeat_at' => now()->subHour()])->save();
    AgentJobs::tick();
    expect($job->refresh()->status)->toBe(AgentJob::FAILED);

    $job->forceFill(['purge_after' => now()->subDay()])->save();
    AgentJobs::tick();
    expect($job->refresh())->input->toBeNull()->log->toBeNull()->error->not->toBeNull();
});

it('lets an admin drive ops over the API with their own token', function () {
    $this->withToken($this->token)->postJson('/api/tantra/ops/runners', ['name' => 'Cloud', 'kind' => 'cloud_task'])
        ->assertCreated()->assertJsonStructure(['token', 'data' => ['id', 'name']]);
    $this->withToken($this->token)->postJson('/api/tantra/ops/jobs', ['kind' => 'ping'])->assertCreated();
    $this->withToken($this->token)->getJson('/api/tantra/ops/jobs')->assertOk()->assertJsonCount(1, 'data');

    config(['tantra.admin_emails' => []]);
    $this->withToken($this->token)->getJson('/api/tantra/ops/jobs')->assertNotFound();
});

it('never sends a Claude account secret to the browser', function () {
    ClaudeAccount::query()->create(['name' => 'Adi cloud', 'kind' => 'cloud_task', 'fire_url' => 'https://api.anthropic.com/x', 'secret' => 'very-secret']);

    $this->actingAs($this->adi)->get('/tantra/ops')
        ->assertDontSee('very-secret')
        ->assertInertia(fn (Assert $page) => $page->where('accounts.0.has_secret', true)->missing('accounts.0.secret'));
});
