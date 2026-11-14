<?php

declare(strict_types=1);

use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;

beforeEach(function () {
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->token = $result['plain_token'];
    WorkspaceSettings::query()->create(['workspace_id' => $result['workspace']->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->adi->id]);
    ImportBatch::execute($this->adi, ['engine_batch_id' => '6', 'posts' => [[
        'engine_post_id' => 'Y61', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-12-01', 'time' => '08:30', 'tz' => 'UTC', 'text' => 'Search is a pipeline channel.',
    ]]]);
});

it('lists review items, the team, tasks and insights over the API as the token\'s user', function () {
    $this->withToken($this->token)->getJson('/api/tantra/review/items?batch=6')->assertOk()
        ->assertJsonPath('data.0.engine_post_id', 'Y61')->assertJsonPath('data.0.can_review', true);
    $this->withToken($this->token)->getJson('/api/tantra/team')->assertOk()->assertJsonPath('data.0.id', $this->adi->id);
    $this->withToken($this->token)->getJson('/api/tantra/tasks')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->token)->getJson('/api/tantra/insights?days=30')->assertOk()->assertJsonStructure(['data']);
});

it('sends review feedback over the API', function () {
    $item = ReviewItem::query()->sole();
    $this->withToken($this->token)->postJson("/api/tantra/review/items/{$item->id}/request-changes", ['note' => 'Shorter hook, please.'])->assertOk();
    expect($item->refresh()->review_status->value)->toBe('changes_requested');
});

it('keeps the ops channel sync for admins', function () {
    $this->withToken($this->token)->postJson('/api/tantra/ops/channels/sync')->assertNotFound();
});
