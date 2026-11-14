<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Review\Actions\DraftMaker;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Mail::fake();
    Storage::fake(config('filesystems.default'));
    $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];

    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'ben', 'review_owner_user_id' => $this->owner->id, 'timezone' => 'UTC', 'default_time' => '09:00']);

    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '08:30')->withTime($day, '12:00');
    }
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn, 'timezone' => 'UTC', 'posting_schedule' => $weekdays]);
});

/** @param array<string, mixed> $overrides */
function ideasPayload(string $key, array $overrides = []): array
{
    return [
        'external_key' => $key,
        'profile' => 'ben',
        'platform' => 'linkedin',
        'title' => "Idea {$key}",
        'hook' => 'Most funnels leak at step two.',
        'format' => 'Text',
        'preview' => ['text' => "Post {$key}.\n\nSecond line.", 'first_comment' => 'The link is here.'],
        'kind' => 'new',
        'target_date' => '2026-11-17',
        ...$overrides,
    ];
}

/** @param list<array<string, mixed>> $ideas */
function ideasPush(object $test, array $ideas): TestResponse
{
    return $test->withHeaders($test->headers)->postJson(route('tantra.api.ideas.upsert'), ['ideas' => $ideas]);
}

function ideasSend(object $test, string $ideaId): TestResponse
{
    return $test->withHeaders($test->headers)->postJson(route('tantra.api.ideas.to-review', $ideaId));
}

it('creates an idea in the first stage and updates it on the same key, never duplicating', function () {
    ideasPush($this, [ideasPayload('ben-b5-01')])
        ->assertOk()
        ->assertJsonPath('data.0.outcome', 'created')
        ->assertJsonPath('data.0.idea.platform', 'linkedin')
        ->assertJsonPath('data.0.idea.state', 'idea')
        ->assertJsonPath('data.0.idea.preview.first_comment', 'The link is here.');

    $idea = Idea::query()->sole();
    expect($idea->workspace_id)->toBe($this->ws->id)
        ->and($idea->idea_stage_id)->toBe($this->ws->ideaStages()->value('id'))
        ->and($idea->title)->toBe('Idea ben-b5-01')
        ->and($idea->body)->toBe("Post ben-b5-01.\n\nSecond line.");

    ideasPush($this, [ideasPayload('ben-b5-01', ['preview' => ['text' => 'Reworked.']])])
        ->assertOk()
        ->assertJsonPath('data.0.outcome', 'updated')
        ->assertJsonPath('counts.updated', 1);

    expect(Idea::query()->count())->toBe(1)
        ->and(IdeaMeta::query()->count())->toBe(1)
        ->and($idea->refresh()->body)->toBe('Reworked.')
        ->and(IdeaMeta::query()->sole()->preview['text'])->toBe('Reworked.');
});

it('puts ideas in the named stage, made once', function () {
    ideasPush($this, [
        ideasPayload('ben-b5-01', ['stage' => 'Batch 5']),
        ideasPayload('ben-b4-01-fb', ['stage' => 'Repurposed', 'kind' => 'repurpose', 'platform' => 'facebook', 'cadence_week' => 2]),
    ])->assertOk();
    ideasPush($this, [ideasPayload('ben-b5-02', ['stage' => 'Batch 5'])])->assertOk();

    expect($this->ws->ideaStages()->where('name', 'Batch 5')->count())->toBe(1)
        ->and(Idea::query()->whereHas('stage', fn ($q) => $q->where('name', 'Batch 5'))->count())->toBe(2)
        ->and(Idea::query()->whereHas('stage', fn ($q) => $q->where('name', 'Repurposed'))->count())->toBe(1);
});

it('leaves a dropped idea and one in review alone', function () {
    ideasPush($this, [ideasPayload('a'), ideasPayload('b')])->assertOk();
    IdeaMeta::query()->where('external_key', 'a')->update(['state' => 'dropped']);
    IdeaMeta::query()->where('external_key', 'b')->update(['state' => 'in_review']);

    ideasPush($this, [ideasPayload('a', ['title' => 'Changed']), ideasPayload('b', ['title' => 'Changed'])])
        ->assertOk()
        ->assertJsonPath('data.0.outcome', 'skipped')
        ->assertJsonPath('data.1.outcome', 'skipped')
        ->assertJsonPath('counts.skipped', 2);

    expect(Idea::query()->where('title', 'Changed')->count())->toBe(0)
        ->and(IdeaMeta::query()->where('external_key', 'a')->value('state'))->toBe('dropped');
});

it('rejects an unknown profile, naming it, and creates nothing', function () {
    ideasPush($this, [ideasPayload('a'), ideasPayload('b', ['profile' => 'nobody'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ideas.1.profile')
        ->assertSee('nobody');

    expect(Idea::query()->count())->toBe(0);
});

it('filters by workspace, profile, kind, state, platform, batch and since', function () {
    ideasPush($this, [
        ideasPayload('new-li', ['stage' => 'Batch 5']),
        ideasPayload('rep-fb', ['stage' => 'Repurposed', 'kind' => 'repurpose', 'platform' => 'facebook']),
        ideasPayload('old-x', ['platform' => 'x', 'state' => 'dropped']),
    ])->assertOk();

    $keys = fn (array $query): array => collect($this->withHeaders($this->headers)->getJson(route('tantra.api.ideas.index', $query))->assertOk()->json('data'))->pluck('external_key')->sort()->values()->all();

    expect($keys([]))->toBe(['new-li', 'old-x', 'rep-fb'])
        ->and($keys(['kind' => 'repurpose']))->toBe(['rep-fb'])
        ->and($keys(['platform' => 'linkedin']))->toBe(['new-li'])
        ->and($keys(['state' => 'dropped']))->toBe(['old-x'])
        ->and($keys(['batch' => '5']))->toBe(['new-li'])
        ->and($keys(['stage' => 'Repurposed']))->toBe(['rep-fb'])
        ->and($keys(['profile' => 'ben', 'workspace_id' => $this->ws->id]))->toBe(['new-li', 'old-x', 'rep-fb'])
        ->and($keys(['since' => '2026-11-14T00:00:00Z']))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', 'UTC'));
    $id = IdeaMeta::query()->where('external_key', 'rep-fb')->value('idea_id');
    $this->withHeaders($this->headers)->patchJson(route('tantra.api.ideas.update', $id), ['hook' => 'A sharper hook.'])
        ->assertOk()
        ->assertJsonPath('data.hook', 'A sharper hook.');

    expect($keys(['since' => '2026-11-14T00:00:00Z']))->toBe(['rep-fb']);
});

it('sends an idea to review once, as a draft on its channel for the review owner, linked both ways', function () {
    $id = ideasPush($this, [ideasPayload('ben-b5-01')])->json('data.0.idea.id');

    ideasSend($this, $id)
        ->assertOk()
        ->assertJsonPath('data.outcome', 'created')
        ->assertJsonPath('data.idea.state', 'in_review');

    $item = ReviewItem::query()->sole();
    $meta = IdeaMeta::query()->sole();
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    expect($item->idea_id)->toBe($id)
        ->and($meta->review_item_id)->toBe($item->id)
        ->and($meta->state)->toBe('in_review')
        ->and($item->engine_batch_id)->toBe('ideas-20261113')
        ->and($item->engine_post_id)->toBe('ben-b5-01')
        ->and($item->workspace_id)->toBe($this->ws->id)
        ->and($item->planned_date->toDateString())->toBe('2026-11-17')
        ->and($item->planned_time)->toBe('08:30')
        ->and($post->social_account_id)->toBe($this->linkedin->id)
        ->and($post->content)->toBe("Post ben-b5-01.\n\nSecond line.")
        ->and(data_get($post->meta, 'thread_replies.0.text'))->toBe('The link is here.');

    ideasSend($this, $id)->assertOk()->assertJsonPath('data.outcome', 'unchanged');
    ideasPush($this, [ideasPayload('ben-b5-01', ['title' => 'Changed'])])->assertJsonPath('data.0.outcome', 'skipped');

    expect(ReviewItem::query()->count())->toBe(1)
        ->and(Post::query()->count())->toBe(1);
});

it('spreads ideas planned for the same day over the channel\'s posting times', function () {
    $ids = collect(ideasPush($this, [ideasPayload('a'), ideasPayload('b'), ideasPayload('c')])->json('data'))->pluck('idea.id');
    $ids->each(fn (string $id) => ideasSend($this, $id)->assertOk());

    expect(ReviewItem::query()->orderBy('engine_post_id')->pluck('planned_time')->all())->toBe(['08:30', '12:00', '08:30']);
});

it('refuses to send while the network has no channel, then drafts on that channel in its post type', function () {
    $id = ideasPush($this, [ideasPayload('ben-fb', ['platform' => 'facebook', 'kind' => 'repurpose', 'cadence_week' => 2])])->json('data.0.idea.id');

    ideasSend($this, $id)->assertUnprocessable()->assertSee('Connect Facebook to publish first.');
    expect(ReviewItem::query()->count())->toBe(0);

    $facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->ws->id, 'timezone' => 'UTC']);
    ideasSend($this, $id)->assertOk();

    $item = ReviewItem::query()->sole();
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    expect($item->platform)->toBe('facebook')
        ->and($post->social_account_id)->toBe($facebook->id)
        ->and($post->content_type)->toBe(ContentType::FacebookPost);

    SocialAccount::factory()->facebook()->create(['workspace_id' => $this->ws->id]);
    $other = ideasPush($this, [ideasPayload('ben-fb-2', ['platform' => 'facebook'])])->json('data.0.idea.id');
    ideasSend($this, $other)->assertUnprocessable()->assertSee('Pick one with channel_id');
});

it('makes an X idea a task posted by hand at its planned time', function () {
    $id = ideasPush($this, [ideasPayload('ben-x', ['platform' => 'x', 'preview' => ['text' => 'Short take.', 'thread' => ['Second post.']]])])->json('data.0.idea.id');

    ideasSend($this, $id)->assertOk();
    $item = ReviewItem::query()->sole();
    expect($item->assisted_manual)->toBeTrue()->and($item->post_group_id)->toBeNull();

    ReviewActions::approve($this->owner, $item);

    $task = ManualTask::query()->where('review_item_id', $item->id)->sole();
    expect($task->kind)->toBe('x_thread')
        ->and($task->due_at->utc()->toDateTimeString())->toBe('2026-11-17 09:00:00');
});

it('holds uploads until the idea goes to review, then attaches them to the draft', function () {
    $token = $this->withHeaders($this->headers)
        ->post(route('tantra.api.uploads.store', ['workspace_id' => $this->ws->id]), ['media' => UploadedFile::fake()->image('a.png', 1080, 1350)])
        ->assertCreated()
        ->json('data.upload_token');

    $id = ideasPush($this, [ideasPayload('img', ['upload_tokens' => [$token], 'preview' => ['text' => 'With an image.', 'media' => ['type' => 'image']]])])
        ->assertOk()
        ->json('data.0.idea.id');

    expect(Media::query()->where('upload_token', $token)->value('collection'))->toBe(DraftMaker::HELD_COLLECTION)
        ->and(IdeaMeta::query()->sole()->preview['media']['files'])->toHaveCount(1);

    ideasSend($this, $id)->assertOk();

    $post = Post::query()->where('post_group_id', ReviewItem::query()->sole()->post_group_id)->sole();
    expect($post->media)->toHaveCount(1);
});

it('patches and deletes ideas of this Account only', function () {
    $id = ideasPush($this, [ideasPayload('a')])->json('data.0.idea.id');

    $this->withHeaders($this->headers)->patchJson(route('tantra.api.ideas.update', $id), ['state' => 'dropped'])
        ->assertOk()
        ->assertJsonPath('data.state', 'dropped');

    $foreign = Idea::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $this->withHeaders($this->headers)->patchJson(route('tantra.api.ideas.update', $foreign->id), ['hook' => 'x'])->assertNotFound();
    $this->withHeaders($this->headers)->deleteJson(route('tantra.api.ideas.destroy', $foreign->id))->assertNotFound();

    $this->withHeaders($this->headers)->deleteJson(route('tantra.api.ideas.destroy', $id))->assertNoContent();

    expect(Idea::query()->whereKey($id)->exists())->toBeFalse()
        ->and(IdeaMeta::query()->count())->toBe(0)
        ->and(Idea::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('answers only the Account owner\'s key', function () {
    $teammate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->ws->members()->attach($teammate->id, membershipPivot('admin'));
    $headers = ['Authorization' => 'Bearer '.passportToken($teammate, $this->ws)];

    $this->withHeaders($headers)->getJson(route('tantra.api.ideas.index'))->assertForbidden();
    $this->withHeaders($headers)->postJson(route('tantra.api.ideas.upsert'), ['ideas' => [ideasPayload('a')]])->assertForbidden();

    expect(Idea::query()->count())->toBe(0);
});
