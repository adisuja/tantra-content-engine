<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Agent\AgentRunner;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Repurpose\RepurposePreset;
use App\Tantra\Repurpose\RepurposeProgress;
use App\Tantra\Repurpose\RepurposeRule;
use App\Tantra\Repurpose\WeeklyCadence;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;

beforeEach(function () {
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->adi->forceFill(['email' => 'adi@example.test', 'email_verified_at' => now()])->save();
    config(['tantra.admin_emails' => ['adi@example.test']]);
    $this->ws = $result['workspace'];
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->adi->id, 'timezone' => 'UTC']);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn]);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::X]);

    ImportBatch::execute($this->adi, ['engine_batch_id' => '4', 'posts' => [[
        'engine_post_id' => 'EA1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'UTC',
        'text' => "One line of funnel maths.\n\nVisitors times show rate times close rate.",
    ]]]);
    $group = (string) str()->uuid();
    $this->item = ReviewItem::query()->where('engine_post_id', 'EA1')->sole();
    $this->item->forceFill(['review_status' => ReviewStatus::Published, 'post_group_id' => $group])->save();
    Post::factory()->forAccount($this->linkedin)->create(['status' => PostStatus::Published, 'published_at' => now()->subDays(8), 'post_group_id' => $group]);

    RepurposePreset::query()->create(['network' => 'x', 'title' => 'LinkedIn to X', 'rules' => 'Threads of 3 to 6 posts.']);
    RepurposeRule::query()->create(['account_id' => $this->adi->account_id, 'network' => 'x', 'rules' => 'No emoji.']);
});

it('lists every channel to repost to and the networks not connected yet', function () {
    $this->actingAs($this->adi)->getJson('/tantra/repost/targets')->assertOk()
        ->assertJsonCount(2, 'data.channels')
        ->assertJson(fn ($json) => $json->where('data.unconnected', fn ($n) => collect($n)->contains('facebook') && ! collect($n)->contains('x'))->etc());
});

it('queues one job with the post, the presets and the Account\'s own rules', function () {
    $this->actingAs($this->adi)->postJson('/tantra/repost', ['review_item_id' => $this->item->id, 'targets' => [
        ['channel_id' => $this->x->id], ['channel_id' => $this->linkedin->id], ['network' => 'facebook'],
    ]])->assertCreated();

    $job = AgentJob::query()->sole();
    expect($job->kind)->toBe('repost')
        ->and($job->input['source']['text'])->toContain('funnel maths')
        ->and($job->input['presets']['x'])->toBe('Threads of 3 to 6 posts.')
        ->and($job->input['custom_rules']['x'])->toBe('No emoji.')
        ->and(collect($job->input['targets'])->firstWhere('channel_id', $this->linkedin->id)['rework'])->toBeTrue();
    // The same channel again is a rework; it does not count as reaching a new network.
    expect(RepurposeProgress::query()->pluck('network')->sort()->values()->all())->toBe(['facebook', 'x']);
});

it('turns the result into ideas, and sends the connected ones to review, once', function () {
    $job = App\Tantra\Repurpose\Reposts::start($this->adi, $this->item, [['channel_id' => $this->x->id], ['network' => 'facebook']]);
    [$runner] = AgentRunner::issue('Laptop', 'local');
    $claimed = AgentJobs::claim($runner, jobId: $job->id);

    $result = ['items' => [
        ['target' => $this->x->id, 'text' => 'Visitors times show rate times close rate.', 'thread' => ['That is the whole model.']],
        ['target' => 'facebook', 'text' => 'One line of funnel maths, for Facebook.', 'first_comment' => 'Link here'],
    ]];
    $summary = AgentJobs::complete($claimed, $result);
    expect($summary)->toContain('1 sent to review')->toContain('1 ready in Ideas');
    AgentJobs::complete($claimed->refresh(), $result);

    expect(IdeaMeta::query()->count())->toBe(2)
        ->and(IdeaMeta::query()->where('platform', 'x')->value('review_item_id'))->not->toBeNull()
        ->and(IdeaMeta::query()->where('platform', 'facebook')->value('state'))->toBe(IdeaMeta::STATE_IDEA)
        ->and(RepurposeProgress::query()->where('network', 'x')->value('status'))->toBe('in_review');
});

it('moves each published post to its next network once its week has come, and only once', function () {
    expect(WeeklyCadence::run())->toBe(['jobs' => 1, 'targets' => 1]);
    $job = AgentJob::query()->sole();
    expect($job->kind)->toBe('repurpose')->and($job->input['targets'][0]['network'])->toBe('facebook')
        ->and($job->input['cadence_week'])->toBe(1);

    expect(WeeklyCadence::run())->toBe(['jobs' => 0, 'targets' => 0]);
});
