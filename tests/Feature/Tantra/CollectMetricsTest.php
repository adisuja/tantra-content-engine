<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Analytics\AccountSnapshot;
use App\Tantra\Analytics\CollectMetrics;
use App\Tantra\Analytics\MetricSnapshot;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 06:05:00', 'UTC'));
    config([
        'tantra.zernio.key' => 'zk', 'tantra.zernio.url' => 'https://zernio.test/api/v1',
        'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'uk',
    ]);

    $result = createApiTestToken();
    $this->adi = $result['user'];

    $tantra = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $tantra->id, 'brand_profile_key' => 'tantra', 'review_owner_user_id' => $this->adi->id]);
    $this->page = SocialAccount::factory()->create(['workspace_id' => $tantra->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => 'zacc']);
    $this->pagePost = Post::factory()->forAccount($this->page)->create([
        'status' => PostStatus::Published, 'published_at' => now()->subDays(2),
        'platform_url' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7526176630860431360/',
    ]);

    $nelbin = User::factory()->create(['account_id' => $this->adi->account_id]);
    $ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $ws->id, 'brand_profile_key' => 'nelbin', 'review_owner_user_id' => $nelbin->id]);
    MemberProfile::query()->create(['user_id' => $nelbin->id, 'unipile_account_id' => 'acc_nelbin', 'unipile_confirmed_at' => now()]);
    $this->personal = Post::factory()->forAccount(SocialAccount::factory()->create(['workspace_id' => $ws->id, 'platform' => Platform::LinkedIn]))->create([
        'status' => PostStatus::Published, 'published_at' => now()->subDays(20),
    ]);
    $this->personal->forceFill(['platform_post_id' => 'urn:li:share:7000000000000000009'])->saveQuietly();

});

// Each test sets its own fakes: the first matching fake wins, so a later one could not override these.
function tantraFakeMetricsApis(): void
{
    Http::fake([
        'zernio.test/api/v1/accounts/zacc/linkedin-post-analytics*' => Http::response(['analytics' => [
            'impressions' => 1000, 'reach' => 800, 'likes' => 40, 'comments' => 8, 'shares' => 2, 'clicks' => 15,
        ]]),
        'zernio.test/api/v1/analytics/linkedin/org-aggregate-analytics*' => Http::response(['metrics' => [
            'followers_gained' => ['total' => 12], 'page_views_total' => ['total' => 90],
        ]]),
        'unipile.test/v2/acc_nelbin/posts/*' => Http::response([
            'id' => 'soc_9', 'reactions_counter' => ['like' => 36, 'love' => 2], 'comments_counter' => 10, 'reposts_counter' => 2, 'analytics' => null,
        ]),
    ]);
}

it('reads page posts from Zernio, personal posts from Unipile, and the page day', function () {
    tantraFakeMetricsApis();

    expect(CollectMetrics::run(app(ZernioClient::class), app(UnipileClient::class)))->toBe(['posts' => 2, 'accounts' => 1, 'skipped' => 0]);

    $page = MetricSnapshot::query()->where('post_id', $this->pagePost->id)->sole();
    expect($page->source)->toBe('zernio')
        ->and($page->impressions)->toBe(1000)
        ->and($page->unique_impressions)->toBe(800)
        ->and($page->clicks)->toBe(15)
        ->and($page->engagement_rate)->toBe(0.05);

    $personal = MetricSnapshot::query()->where('post_id', $this->personal->id)->sole();
    expect($personal->source)->toBe('unipile')
        ->and($personal->reactions)->toBe(38)
        ->and($personal->comments)->toBe(10)
        ->and($personal->impressions)->toBeNull()
        ->and($personal->engagement_rate)->toBeNull();

    $day = AccountSnapshot::query()->sole();
    expect($day->date->toDateString())->toBe('2026-11-23')
        ->and($day->follower_gain)->toBe(12)
        ->and($day->page_views)->toBe(90);

    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'linkedin-post-analytics?urn='.rawurlencode('urn:li:ugcPost:7526176630860431360')));

    // A second pass straight after reads nothing again.
    expect(CollectMetrics::run(app(ZernioClient::class), app(UnipileClient::class))['posts'])->toBe(0);
});

it('reads posts older than 14 days only at the morning run', function () {
    tantraFakeMetricsApis();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 18:05:00', 'UTC'));

    CollectMetrics::run(app(ZernioClient::class), app(UnipileClient::class));

    expect(MetricSnapshot::query()->pluck('post_id')->all())->toBe([$this->pagePost->id])
        ->and(AccountSnapshot::query()->count())->toBe(0);
});

it('stops asking Zernio for a day when analytics need a paid add-on', function () {
    Http::fake(['zernio.test/*' => Http::response(['error' => 'Analytics add-on required'], 402), 'unipile.test/*' => Http::response([])]);

    CollectMetrics::run(app(ZernioClient::class), app(UnipileClient::class));
    $this->travel(7)->hours();
    CollectMetrics::run(app(ZernioClient::class), app(UnipileClient::class));

    // The first pass: one Zernio call that got 402, one Unipile call. The second: no Zernio call at all.
    Http::assertSentCount(2);
    expect(MetricSnapshot::query()->count())->toBe(0);
});
