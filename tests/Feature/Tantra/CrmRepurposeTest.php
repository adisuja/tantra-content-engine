<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's Content > Repurpose: the posts that can be repurposed, the channels they can go to, and the Engine's own Reposts job.

beforeEach(function () {
    Http::fake();
    $this->owner = User::factory()->create(['email' => 'owner@example.test']);
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    $this->second = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Aquila']);
    $this->unmapped = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Hidden']);
    WorkspaceSettings::query()->create(['workspace_id' => $this->team->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id, 'timezone' => 'UTC']);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Adi Murali']);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::X, 'display_name' => 'Tantra on X']);
    $this->hidden = SocialAccount::factory()->create(['workspace_id' => $this->unmapped->id, 'platform' => Platform::Bluesky, 'display_name' => 'Hidden Bluesky']);
    $stranger = User::factory()->create();
    $this->otherWs = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id}|{$this->second->id},ws_other={$this->otherWs->id}",
    ]);
    ImportBatch::execute($this->owner, ['engine_batch_id' => '7', 'posts' => [
        ['engine_post_id' => 'RP1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'UTC', 'text' => "One line of funnel maths.\n\nVisitors times show rate times close rate."],
        ['engine_post_id' => 'RP2', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-25', 'time' => '08:30', 'tz' => 'UTC', 'text' => 'A post about the webinar.'],
    ]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'RP1')->sole();
    $this->webinar = ReviewItem::query()->where('engine_post_id', 'RP2')->sole();
    $this->webinar->forceFill(['review_status' => ReviewStatus::Rejected])->save();
});

function crmRep(object $test, string $path, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))->postJson("/api/tantra/engagement/repurpose/{$path}", $body);
}

it('answers 401 to a missing or wrong key on all three', function () {
    crmRep($this, 'sources', ['workspace' => 'ws_team'], null)->assertUnauthorized();
    crmRep($this, 'targets', ['workspace' => 'ws_team', 'actor_email' => 'owner@example.test'], 'nope')->assertUnauthorized();
    crmRep($this, 'start', ['workspace' => 'ws_team'], null)->assertUnauthorized();
    expect(AgentJob::query()->count())->toBe(0);
});

it('lists the posts that can be repurposed, without rejected ones, searchable, and never another team\'s', function () {
    $r = crmRep($this, 'sources', ['workspace' => 'ws_team'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(collect($r->json('data'))->pluck('text')->all())->toHaveCount(1)->and($r->json('data.0.text'))->toContain('funnel maths')
        ->and($r->json('data.0'))->toHaveKeys(['id', 'platform', 'network', 'status', 'text', 'planned_date', 'updated_at'])
        ->and($r->json('data.0'))->not->toHaveKeys(['workspace_id', 'account_id', 'batch_id', 'payload']);
    crmRep($this, 'sources', ['workspace' => 'ws_team', 'q' => 'FUNNEL'])->assertJsonCount(1, 'data');
    crmRep($this, 'sources', ['workspace' => 'ws_team', 'q' => 'zebra'])->assertJsonCount(0, 'data');
    crmRep($this, 'sources', ['workspace' => 'ws_other'])->assertOk()->assertJsonCount(0, 'data');
    crmRep($this, 'sources', ['workspace' => 'ws_unknown'])->assertOk()->assertJsonPath('meta.total', 0);
});

it('lists the channels of the team a post can go to, and the networks not connected, never a channel of an unmapped workspace', function () {
    $r = crmRep($this, 'targets', ['workspace' => 'ws_team', 'actor_email' => 'owner@example.test'])->assertOk();
    $names = collect($r->json('data.channels'))->pluck('name')->all();
    expect($names)->toEqualCanonicalizing(['Adi Murali', 'Tantra on X'])->and($r->json('data.unconnected'))->toContain('facebook')->not->toContain('x')
        ->and($r->json('data.channels.0'))->not->toHaveKeys(['workspace_id', 'avatar']);
    crmRep($this, 'targets', ['workspace' => 'ws_team', 'actor_email' => 'stranger@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
});

it('queues one job through the Engine\'s own Reposts, as the person, for the channels and networks picked', function () {
    crmRep($this, 'start', ['workspace' => 'ws_team', 'actor_email' => 'owner@example.test', 'review_item_id' => $this->item->id,
        'targets' => [['channel_id' => $this->x->id], ['network' => 'facebook']], 'note' => 'Keep it short.'])->assertCreated()->assertJsonPath('data.kind', 'repost');
    $job = AgentJob::query()->sole();
    expect($job->input['source']['text'])->toContain('funnel maths')->and($job->input['note'])->toBe('Keep it short.')
        ->and(collect($job->input['targets'])->pluck('network')->sort()->values()->all())->toBe(['facebook', 'x']);
});

it('refuses another team\'s post, a rejected post, a channel from outside the team, an unknown person and an empty pick', function () {
    $base = ['workspace' => 'ws_team', 'actor_email' => 'owner@example.test', 'review_item_id' => $this->item->id, 'targets' => [['channel_id' => $this->x->id]]];
    crmRep($this, 'start', [...$base, 'workspace' => 'ws_other'])->assertNotFound();
    crmRep($this, 'start', [...$base, 'workspace' => 'ws_unknown'])->assertNotFound();
    crmRep($this, 'start', [...$base, 'review_item_id' => $this->webinar->id])->assertNotFound();
    crmRep($this, 'start', [...$base, 'review_item_id' => (string) str()->uuid()])->assertNotFound();
    crmRep($this, 'start', [...$base, 'targets' => [['channel_id' => $this->hidden->id]]])->assertNotFound()->assertJsonPath('error', 'unknown_channel');
    crmRep($this, 'start', [...$base, 'actor_email' => 'stranger@example.test'])->assertForbidden();
    crmRep($this, 'start', [...$base, 'targets' => []])->assertUnprocessable();
    crmRep($this, 'start', [...$base, 'targets' => [['network' => 'myspace']]])->assertUnprocessable();
    expect(AgentJob::query()->count())->toBe(0);
});
