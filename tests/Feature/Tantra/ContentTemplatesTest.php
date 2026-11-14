<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Agent\AgentRunner;
use App\Tantra\Templates\ContentTemplate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->adi->forceFill(['email' => 'adi@example.test', 'email_verified_at' => now()])->save();
    $this->token = $result['plain_token'];
    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id]);
    $this->ben->update(['current_workspace_id' => $result['workspace']->id]);
    $result['workspace']->members()->attach($this->ben->id, membershipPivot('member'));
    config(['tantra.admin_emails' => ['adi@example.test']]);
});

it('seeds samples as templates by key, and never overwrites one built from a post', function () {
    $this->withToken($this->token)->postJson('/api/tantra/templates', ['templates' => [
        ['key' => 'BK18', 'title' => 'One line of funnel maths', 'format' => 'Promo Graphic', 'creator' => 'Bharath Krishna Pai', 'spec' => 'Dark ground, one equation.'],
        ['key' => 'AB34', 'title' => 'Funnel from one brief', 'format' => 'Educational Carousel'],
    ]])->assertOk()->assertJsonPath('created', 2);

    $this->withToken($this->token)->postJson('/api/tantra/templates', ['templates' => [['key' => 'BK18', 'title' => 'Renamed']]])
        ->assertJsonPath('updated', 1);
    expect(ContentTemplate::query()->where('key', 'BK18')->value('title'))->toBe('Renamed');

    $this->withToken($this->token)->getJson('/api/tantra/templates?origin=seed')->assertOk()->assertJsonCount(2, 'data');
});

it('shows the gallery under Create', function () {
    ContentTemplate::query()->create(['key' => 'NJ07', 'title' => 'Entry offer', 'origin' => 'seed']);

    $this->actingAs($this->ben)->get('/tantra/templates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/templates/Index')->where('templates.0.key', 'NJ07'));
});

it('builds a template from a pasted post through an agent job, once', function () {
    $this->actingAs($this->adi)->postJson('/tantra/templates/intake', ['url' => 'https://www.instagram.com/p/abc123/', 'note' => 'The hook'])
        ->assertCreated()->assertJsonPath('data.platform', 'instagram')->assertJsonPath('data.state', 'building');

    $template = ContentTemplate::query()->where('origin', 'intake')->sole();
    $job = AgentJob::query()->sole();
    expect($job->kind)->toBe('template_from_post')->and($job->input['url'])->toBe('https://www.instagram.com/p/abc123/')
        ->and($template->agent_job_id)->toBe($job->id);

    [$runner] = AgentRunner::issue('Laptop', 'local');
    $claimed = AgentJobs::claim($runner);
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $result = ['title' => 'Three-panel before and after', 'format' => 'Carousel', 'platform' => 'instagram', 'description' => 'A before, a turn, an after.',
        'spec' => '# Layout', 'sample_md' => '# T-1', 'palette' => ['#111111', '#FAFAFA'], 'preview_base64' => $png];

    expect(AgentJobs::complete($claimed, $result))->toContain('is live');
    expect(AgentJobs::complete($claimed->refresh(), [...$result, 'title' => 'Other']))->toContain('is live');

    $template->refresh();
    expect($template->status)->toBe('live')->and($template->title)->toBe('Three-panel before and after')
        ->and($template->preview_path)->toBe("tantra/templates/{$template->key}/preview.png");
    Storage::assertExists($template->preview_path);

    // The same post again does not make a second template.
    $this->actingAs($this->adi)->postJson('/tantra/templates/intake', ['url' => 'https://www.instagram.com/p/abc123/'])->assertCreated();
    expect(ContentTemplate::query()->where('origin', 'intake')->count())->toBe(1);
});

it('holds a teammate\'s post for the team and refuses addresses that are not posts', function () {
    $this->actingAs($this->ben)->postJson('/tantra/templates/intake', ['url' => 'https://www.youtube.com/watch?v=xyz'])
        ->assertCreated()->assertJsonPath('data.state', 'waiting');
    expect(AgentJob::query()->sole()->status)->toBe(AgentJob::AWAITING_ADMIN);

    $this->actingAs($this->ben)->postJson('/tantra/templates/intake', ['url' => 'https://example.com/post'])->assertUnprocessable();
});

it('refuses a result without the parts a template needs', function () {
    $this->actingAs($this->adi)->postJson('/tantra/templates/intake', ['url' => 'https://x.com/someone/status/123'])->assertCreated();
    [$runner] = AgentRunner::issue('Laptop', 'local');
    $claimed = AgentJobs::claim($runner);

    expect(fn () => AgentJobs::complete($claimed, ['title' => 'Only a title']))->toThrow(App\Tantra\Agent\InvalidResult::class);
    expect($claimed->refresh()->status)->toBe(AgentJob::RUNNING);
});
