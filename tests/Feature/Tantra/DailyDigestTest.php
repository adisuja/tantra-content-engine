<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Console\DailyDigestCommand;
use App\Tantra\Digest\DailyDigest;
use App\Tantra\Digest\DigestReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/** A member with a workspace, published and scheduled posts and views on two networks. */
function digestWorld(): array
{
    $user = User::factory()->create(['name' => 'Adi Murali', 'timezone' => 'Asia/Kolkata']);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);

    Post::factory()->forAccount($account)->count(2)->create(['status' => PostStatus::Published, 'published_at' => now()->subHours(3)]);
    Post::factory()->forAccount($account)->create(['status' => PostStatus::Published, 'published_at' => now()->subDays(9)]);
    Post::factory()->forAccount($account)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(2)]);
    Post::factory()->forAccount($account)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(12)]);

    $views = function (Platform $platform, array $byDay, string $excerpt) use ($workspace): void {
        $publication = AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform, 'network' => $platform->network(), 'excerpt' => $excerpt, 'provider_published_at' => now()->subDays(5)]);
        foreach ($byDay as $daysAgo => $count) {
            AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => today()->subDays($daysAgo), 'views_count' => null, 'impressions_count' => $count]);
        }
    };
    $views(Platform::LinkedIn, [2 => 900, 1 => 1400, 0 => 2100], 'Seven hooks that got us demos');
    $views(Platform::Instagram, [1 => 300, 0 => 450], 'Behind the scenes');

    return [$user, $workspace];
}

it('sums each post\'s latest views per network and the change since yesterday', function () {
    [$user] = digestWorld();
    $report = DigestReport::for($user);

    expect($report->viewsByNetwork())->toBe(['linkedin' => 2100, 'instagram' => 450])
        ->and(array_sum($report->viewsByNetwork(CarbonImmutable::now()->subDay())))->toBe(1700)
        ->and($report->publishedSince(CarbonImmutable::now()->subDay()))->toBe(2)
        ->and($report->publishedSince(null))->toBe(3)
        ->and($report->scheduledNextDays(7))->toBe(1);

    $stats = DailyDigestCommand::stats($report, CarbonImmutable::now('Asia/Kolkata'));
    expect($stats['views'])->toBe(2550)
        ->and($stats['views_delta'])->toBe(850)
        ->and($stats['standout']['value'])->toBe('2,100')
        ->and($stats['standout']['label'])->toBe('views on your best post this month')
        ->and($stats['standout']['math'])->toContain('Seven hooks that got us demos');
});

it('falls back to posts published when there are no views or clicks, and has nothing to say for an empty account', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $report = DigestReport::for($user);

    expect($report->standout(0))->toBeNull()
        ->and($report->standout(4))->toMatchArray(['value' => '4', 'label' => 'posts published for you']);
});

it('sends at the digest hour in the member\'s own timezone, once a day, and not when turned off', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 02:31', 'UTC')); // 08:01 in Kolkata
    [$user] = digestWorld();
    $this->artisan('tantra:daily-digest')->assertSuccessful();
    $this->artisan('tantra:daily-digest')->assertSuccessful();
    Mail::assertQueuedCount(1);
    Mail::assertQueued(DailyDigest::class, fn (DailyDigest $m): bool => $m->hasTo($user->email)
        && $m->envelope()->subject === 'Your day on Tantra: 2 went live, +850 views');

    $this->travelTo(CarbonImmutable::parse('2026-11-21 05:31', 'UTC')); // 11:01 in Kolkata: not the hour
    $this->artisan('tantra:daily-digest')->assertSuccessful();
    Mail::assertQueuedCount(1);

    NotificationPreference::query()->where('user_id', $user->id)->update(['daily_digest' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 02:31', 'UTC'));
    $this->artisan('tantra:daily-digest')->assertSuccessful();
    Mail::assertQueuedCount(1);
});

it('renders the digest with the numbers, the network bars and a one-click unsubscribe', function () {
    [$user] = digestWorld();
    $stats = DailyDigestCommand::stats(DigestReport::for($user), CarbonImmutable::now('Asia/Kolkata'));
    $mail = new DailyDigest('Adi', $stats, URL::signedRoute('tantra.digest.unsubscribe', ['user' => $user->id]), 'Asia/Kolkata');

    $mail->assertSeeInHtml('2,550', false)
        ->assertSeeInHtml('+850 since yesterday', false)
        ->assertSeeInHtml('LinkedIn')
        ->assertSeeInHtml('Instagram')
        ->assertSeeInHtml('How we count');
    expect($mail->headers()->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and(strlen($mail->render()))->toBeLessThan(102 * 1024);
});

it('turns the digest off only on POST from the signed link', function () {
    [$user] = digestWorld();
    $url = URL::signedRoute('tantra.digest.unsubscribe', ['user' => $user->id]);

    $this->get($url)->assertOk()->assertSee('Turn it off');
    expect(NotificationPreference::query()->where('user_id', $user->id)->value('daily_digest'))->not->toBeFalse();

    $this->post($url)->assertOk()->assertSee('The daily digest is off');
    expect(NotificationPreference::query()->where('user_id', $user->id)->value('daily_digest'))->toBeFalse();

    $this->post(route('tantra.digest.unsubscribe', ['user' => $user->id]))->assertForbidden();
});
