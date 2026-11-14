<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Agent\AgentRunner;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Rebrand\Rebrand;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->adi->forceFill(['email' => 'adi@example.test', 'email_verified_at' => now()])->save();
    config(['tantra.admin_emails' => ['adi@example.test'], 'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);
    $this->timmy = User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'Timmy Joy Ignatious']);
    MemberProfile::query()->create(['user_id' => $this->timmy->id, 'unipile_account_id' => 'acc_timmy', 'linkedin_headline' => 'Business Growth Strategist', 'expertise' => 'SEO and webinar funnels']);
});

function proposal(): array
{
    return ['headline_options' => ['SEO, outbound and webinar funnels for B2B teams | Tantra', 'Second option'], 'about' => 'I help B2B teams turn search and outbound into pipeline.', 'banner' => ['brief' => 'Dark ground, one line.'], 'featured' => [['title' => 'The webinar funnel guide']]];
}

it('writes a rebrand through an agent job and shows it to the person', function () {
    Http::fake(['unipile.test/v2/acc_timmy/users/me' => Http::response(['summary' => 'About me', 'location' => 'San Jose'])]);

    $this->actingAs($this->adi)->postJson("/tantra/team/{$this->timmy->id}/rebrand")->assertCreated();
    $job = AgentJob::query()->sole();
    expect($job->kind)->toBe('rebrand')->and($job->input['person']['about'])->toBe('About me')
        ->and($job->input['role']['expertise'])->toBe('SEO and webinar funnels');

    [$runner] = AgentRunner::issue('Laptop', 'local');
    AgentJobs::complete(AgentJobs::claim($runner), proposal());
    expect(Rebrand::query()->sole()->status)->toBe('proposed');

    $this->actingAs($this->timmy)->getJson('/tantra/rebrands')->assertOk()->assertJsonPath('data.0.proposal.about', 'I help B2B teams turn search and outbound into pipeline.');
});

it('lets only the person put the headline on LinkedIn, and checks it took', function () {
    $rebrand = Rebrand::query()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->timmy->id, 'status' => 'proposed', 'proposal' => proposal()]);
    $headline = 'SEO, outbound and webinar funnels for B2B teams | Tantra';
    Http::fake([
        'unipile.test/v2/acc_timmy/users/me' => Http::sequence()
            ->push(['object' => 'UserProfileUpdated'])
            ->push(['headline' => $headline]),
    ]);

    $this->actingAs($this->adi)->postJson("/tantra/rebrands/{$rebrand->id}/headline", ['headline' => $headline])->assertForbidden();
    $this->actingAs($this->timmy)->postJson("/tantra/rebrands/{$rebrand->id}/headline", ['headline' => $headline])
        ->assertOk()->assertJsonPath('applied', true);

    expect($rebrand->refresh()->status)->toBe('applied')
        ->and(MemberProfile::query()->find($this->timmy->id)->linkedin_headline)->toBe($headline);
    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['headline'] === $headline);
});

it('refuses a proposal with dashes or without a headline', function () {
    $this->actingAs($this->timmy)->postJson("/tantra/team/{$this->timmy->id}/rebrand")->assertCreated();
    $this->actingAs($this->adi)->postJson('/tantra/ops/jobs/'.AgentJob::query()->sole()->id.'/run')->assertOk();
    [$runner] = AgentRunner::issue('Laptop', 'local');
    $claimed = AgentJobs::claim($runner);

    expect(fn () => AgentJobs::complete($claimed, [...proposal(), 'about' => "A line \u{2014} with a dash"]))->toThrow(App\Tantra\Agent\InvalidResult::class);
    expect(fn () => AgentJobs::complete($claimed, ['about' => 'x']))->toThrow(App\Tantra\Agent\InvalidResult::class);
});
