<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Tantra\Engagement\PublishedPost;
use App\Tantra\Notify\SendToCrm;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// One Tantra R1: a published post becomes a watched post in Tantra's Engagement. Nothing is sent to any network.
beforeEach(function () {
    // The CRM is connected only after the batch is imported below: ImportBatch sends its own review_ready event, which
    // is not what these tests are about and would be a stray request to the CRM.
    config(['tantra.crm.intake_key' => null, 'tantra.crm.url' => 'https://crm.test', 'tantra.crm.engagement.posts' => true]);
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id, 'name' => 'Tantra']);
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_adi', 'display_name' => 'Adi Suja']);
    // The batch below names the profile "adi": ImportBatch refuses a profile no workspace is mapped to (tantra:map-profile).
    WorkspaceSettings::query()->updateOrCreate(['workspace_id' => $this->ws->id], ['brand_profile_key' => 'adi', 'review_owner_user_id' => $this->adi->id]);

    ImportBatch::execute($this->adi, ['engine_batch_id' => '9', 'posts' => [[
        'engine_post_id' => 'AG9', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30',
        'tz' => 'Asia/Kolkata', 'text' => "Seven prompts I would start with\nMore lines.",
        'resource' => ['name' => 'Prompt Pack', 'keyword' => 'PROMPTS', 'url' => 'https://res.test/prompts'],
    ]]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'AG9')->sole();
    $this->post = Post::query()->where('post_group_id', $this->item->post_group_id)->sole();
    $this->post->forceFill([
        'workspace_id' => $this->ws->id, 'social_account_id' => $this->account->id, 'platform' => Platform::UnipileLinkedIn, 'user_id' => $this->adi->id,
        'status' => PostStatus::Published, 'published_at' => now()->subHour(), 'platform_url' => 'https://www.linkedin.com/feed/update/urn:li:share:7400000000000000001/',
    ])->saveQuietly();
    config(['tantra.crm.intake_key' => 'k']);
    // From here on, any email is one the tests below caused.
    Mail::fake();
});

it('sends engine.post_published for Engagement only, with the post, the Unipile account and the giveaway', function () {
    Http::fake(['crm.test/intake/engine' => Http::response(['ok' => true, 'deduped' => false], 202)]);

    expect(PublishedPost::send($this->post->fresh()))->toBeTrue();

    Http::assertSent(fn ($r) => $r->url() === 'https://crm.test/intake/engine'
        && $r['event'] === 'engine.post_published'
        && $r['data']['notify'] === false
        && $r['data']['post']['id'] === (string) $this->post->id
        && $r['data']['post']['platform'] === 'unipile-linkedin'
        && $r['data']['post']['url'] === 'https://www.linkedin.com/feed/update/urn:li:share:7400000000000000001/'
        && $r['data']['post']['account']['unipile_account_id'] === 'acc_adi'
        && $r['data']['post']['keyword'] === 'PROMPTS'
        && $r['data']['post']['asset']['resource']['name'] === 'Prompt Pack'
        && $r['message']['html'] === null);
    Mail::assertNothingQueued();
});

it('uses one stable event id per post, so a second send is deduplicated by the CRM', function () {
    Queue::fake();
    PublishedPost::send($this->post);
    PublishedPost::send($this->post);
    $ids = [];
    Queue::assertPushed(SendToCrm::class, function (SendToCrm $job) use (&$ids) {
        $ids[] = $job->id;

        return $job->event === 'engine.post_published' && $job->mail === null;
    });
    expect(array_unique($ids))->toHaveCount(1)
        ->and(PublishedPost::eventId($this->post, 'backfill'))->not->toBe($ids[0]);
});

it('sends nothing when the CRM is not connected, the switch is off, or the post has no link', function () {
    Queue::fake();
    config(['tantra.crm.engagement.posts' => false]);
    expect(PublishedPost::send($this->post))->toBeFalse();
    config(['tantra.crm.engagement.posts' => true, 'tantra.crm.intake_key' => null]);
    expect(PublishedPost::send($this->post))->toBeFalse();
    config(['tantra.crm.intake_key' => 'k']);
    $this->post->forceFill(['platform_url' => null])->saveQuietly();
    expect(PublishedPost::send($this->post->fresh()))->toBeFalse();
    Queue::assertNothingPushed();
});

it('the backfill lists the last 30 days with --dry-run and sends nothing, then sends each post once', function () {
    Queue::fake();
    $this->artisan('tantra:engagement-backfill', ['--dry-run' => true])
        ->expectsOutputToContain('1 posts from the last 30 days would be sent. Nothing was sent (dry run).')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('tantra:engagement-backfill')->expectsOutputToContain('1 of 1 posts queued for Engagement.')->assertSuccessful();
    Queue::assertPushed(SendToCrm::class, fn (SendToCrm $job) => $job->id === PublishedPost::eventId($this->post, 'backfill'));
});
