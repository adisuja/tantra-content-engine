<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Ops\Health;
use App\Tantra\Ops\MediaCheck;
use App\Tantra\Ops\MediaMissing;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['tantra.zernio.key' => null, 'tantra.unipile.key' => null, 'tantra.unipile.dsn' => null]);
});

it('fails the report until the scheduler ticks, then passes', function () {
    expect(Health::report()['ok'])->toBeFalse()
        ->and(Health::report()['problems'])->toContain('the scheduler has not run in the last 5 minutes');

    $this->artisan('tantra:heartbeat')->assertSuccessful();

    $report = Health::report();
    expect($report['ok'])->toBeTrue()
        ->and($report['failed_jobs_24h'])->toBe(0)
        ->and($report['zernio'])->toBeNull();

    $this->travel(10)->minutes();
    $this->artisan('tantra:health')->assertFailed();
});

it('answers the pinger only with its token', function () {
    Health::tick();

    $this->getJson('/tantra/health')->assertNotFound();

    config(['tantra.health.token' => 'ping-secret']);
    $this->getJson('/tantra/health', ['Authorization' => 'Bearer wrong'])->assertNotFound();
    $this->getJson('/tantra/health', ['Authorization' => 'Bearer ping-secret'])->assertOk()->assertJsonPath('ok', true);
});

it('tells the review owner once when a post due soon has lost its file', function () {
    Storage::fake(config('filesystems.default'));
    Mail::fake();

    $result = createApiTestToken();
    WorkspaceSettings::query()->create(['workspace_id' => $result['workspace']->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $result['user']->id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $result['workspace']->id, 'platform' => Platform::LinkedIn]);

    Storage::put('medias/here.png', 'png');
    $media = fn (string $path): array => [['id' => (string) str()->uuid(), 'path' => $path, 'url' => "https://media.test/{$path}", 'mime_type' => 'image/png']];
    Post::factory()->forAccount($channel)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addHours(3), 'media' => $media('medias/here.png')]);
    Post::factory()->forAccount($channel)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addHours(5), 'media' => $media('medias/gone.png')]);

    expect(MediaCheck::run())->toBe(1)->and(MediaCheck::run())->toBe(1);
    Mail::assertQueuedCount(1);
    Mail::assertQueued(MediaMissing::class, fn (MediaMissing $m): bool => $m->hasTo($result['user']->email));
});
