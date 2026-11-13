<?php

declare(strict_types=1);

use App\Dto\Analytics\DiscoveredPublication;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Tantra\Analytics\CollectMetrics;
use App\Tantra\Analytics\Collectors\UnipileLinkedInFollowerCollector;
use App\Tantra\Analytics\Collectors\UnipileLinkedInMetricsCollector;
use App\Tantra\Analytics\Collectors\UnipileLinkedInPublicationCollector;
use App\Tantra\Analytics\Collectors\ZernioPageFollowerCollector;
use App\Tantra\Analytics\Collectors\ZernioPageMetricsCollector;
use App\Tantra\Analytics\Collectors\ZernioPagePublicationCollector;
use App\Tantra\Analytics\MetricSnapshot;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Each test reads followers and history itself; creating a channel must not start them.
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 06:05:00', 'UTC'));
    config([
        'tantra.zernio.key' => 'zk', 'tantra.zernio.url' => 'https://zernio.test/api/v1',
        'tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'uk',
    ]);

    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->adiWs = $result['workspace'];
    WorkspaceSettings::query()->create(['workspace_id' => $this->adiWs->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->adi->id]);

    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id]);
    $this->benWs = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->benWs->id, 'brand_profile_key' => 'ben', 'review_owner_user_id' => $this->ben->id]);
    $this->unipileChannel = SocialAccount::factory()->create([
        'workspace_id' => $this->benWs->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_ben', 'access_token' => 'unipile-managed',
    ]);
});

/** Three of Ben's posts as Unipile lists them: one the Engine published, one of his own, one repost. */
function benPosts(?string $nextCursor = null): array
{
    return [
        'object' => 'PostList',
        'data' => [
            [
                'id' => '7400', 'share_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7400000000000000000', 'created_at' => '2026-11-24T08:30:00.000Z',
                'text' => 'Three numbers every RevOps lead should know.', 'is_repost' => false, 'attachments' => [['type' => 'img', 'url' => 'https://media.licdn.test/a.jpg']],
                'reactions_counter' => [['reaction' => 'like', 'count' => 36], ['reaction' => 'love', 'count' => 2]],
                'comments_counter' => 10, 'reposts_counter' => 2, 'analytics' => ['impressions_counter' => 1500],
            ],
            [
                'id' => '7399', 'share_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7399000000000000000', 'created_at' => '2026-11-20T08:30:00.000Z',
                'text' => 'Written on LinkedIn itself.', 'is_repost' => false, 'attachments' => [],
                'reactions_counter' => [['reaction' => 'like', 'count' => 5]], 'comments_counter' => 1, 'reposts_counter' => 0,
            ],
            [
                'id' => '7398', 'share_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7398000000000000000', 'created_at' => '2026-11-19T08:30:00.000Z',
                'text' => 'Someone else\'s post.', 'is_repost' => true, 'attachments' => [],
            ],
        ],
        'next_cursor' => $nextCursor,
    ];
}

function enginePost(SocialAccount $channel, string $platformPostId, array $attributes = []): Post
{
    $post = Post::factory()->forAccount($channel)->create(['status' => PostStatus::Published, 'published_at' => now()->subDay(), ...$attributes]);
    $post->forceFill(['platform_post_id' => $platformPostId])->saveQuietly();

    return $post;
}

it('pages a profile\'s own posts through Unipile with the limit and cursor', function () {
    Http::fake(['unipile.test/v2/acc_ben/users/me/posts*' => Http::response(benPosts('c2'))]);

    $response = app(UnipileClient::class)->ownPosts('acc_ben', 25, 'c1');

    expect($response->json('next_cursor'))->toBe('c2');
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://unipile.test/v2/acc_ben/users/me/posts?limit=25&cursor=c1'
        && $r->header('X-API-KEY')[0] === 'uk');
});

it('registers a collector for each LinkedIn channel in the upstream factories', function () {
    expect(Platform::LinkedIn->isIncludedInAnalytics())->toBeTrue()
        ->and(Platform::UnipileLinkedIn->isIncludedInAnalytics())->toBeTrue()
        ->and(Platform::ZernioLinkedInPage->isIncludedInAnalytics())->toBeTrue()
        ->and(Platform::LinkedInPage->isIncludedInAnalytics())->toBeFalse()
        ->and(app(FollowerCollectorFactory::class)->for(Platform::UnipileLinkedIn))->toBeInstanceOf(UnipileLinkedInFollowerCollector::class)
        ->and(app(FollowerCollectorFactory::class)->for(Platform::LinkedIn))->toBeInstanceOf(UnipileLinkedInFollowerCollector::class)
        ->and(app(FollowerCollectorFactory::class)->for(Platform::ZernioLinkedInPage))->toBeInstanceOf(ZernioPageFollowerCollector::class)
        ->and(app(PublicationHistoryCollectorFactory::class)->for($this->unipileChannel))->toBeInstanceOf(UnipileLinkedInPublicationCollector::class)
        ->and(app(PublicationMetricsCollectorFactory::class)->for(Platform::LinkedIn))->toBeInstanceOf(UnipileLinkedInMetricsCollector::class)
        ->and(app(PublicationMetricsCollectorFactory::class)->for(Platform::ZernioLinkedInPage))->toBeInstanceOf(ZernioPageMetricsCollector::class);
});

it('reads a Unipile channel\'s followers once from users/me, then from the cache', function () {
    Http::fake(['unipile.test/v2/acc_ben/users/me' => Http::response(['id' => 'ACoAABen', 'followers_count' => 512])]);
    $collector = app(UnipileLinkedInFollowerCollector::class);

    $first = $collector->collect($this->unipileChannel, CarbonImmutable::parse('2026-11-25'));
    $second = $collector->collect($this->unipileChannel, CarbonImmutable::parse('2026-11-25'));

    expect($first->followers)->toBe(512)->and($second->followers)->toBe(512);
    Http::assertSentCount(1);
});

it('takes the follower count the identity sync stored today without asking Unipile', function () {
    Http::fake();
    MemberProfile::query()->create(['user_id' => $this->ben->id, 'unipile_account_id' => 'acc_ben', 'unipile_confirmed_at' => now(), 'linkedin_followers' => 640, 'linkedin_synced_at' => now()->subHours(3)]);

    expect(app(UnipileLinkedInFollowerCollector::class)->collect($this->unipileChannel, CarbonImmutable::parse('2026-11-25'))->followers)->toBe(640);
    Http::assertNothingSent();
});

it('lists a profile\'s posts, leaves reposts out and keeps the id the Engine stored', function () {
    Http::fake(['unipile.test/v2/acc_ben/users/me/posts*' => Http::response(benPosts('c2'))]);
    enginePost($this->unipileChannel, '7400');

    $page = app(UnipileLinkedInPublicationCollector::class)->page($this->unipileChannel, null, CarbonImmutable::parse('2025-11-25'));

    expect(array_map(fn (DiscoveredPublication $p): string => $p->providerPostId, $page->publications))->toBe(['7400', '7399'])
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Image)
        ->and($page->publications[0]->permalink)->toBe('https://www.linkedin.com/feed/update/urn:li:activity:7400000000000000000')
        ->and($page->publications[0]->providerMetadata)->toMatchArray(['impressions' => 1500, 'reactions' => 38, 'comments' => 10, 'reposts' => 2])
        ->and($page->publications[1]->providerMetadata['impressions'])->toBeNull()
        ->and($page->nextCursor)->toBe('c2')
        ->and($page->providerExhausted)->toBeFalse();
});

it('stops at the cutoff', function () {
    Http::fake(['unipile.test/v2/acc_ben/users/me/posts*' => Http::response(benPosts('c2'))]);

    $page = app(UnipileLinkedInPublicationCollector::class)->page($this->unipileChannel, null, CarbonImmutable::parse('2026-11-22'));

    expect($page->publications)->toHaveCount(1)
        ->and($page->nextCursor)->toBeNull()
        ->and($page->providerExhausted)->toBeTrue();
});

it('reads every post\'s numbers from one cached list read', function () {
    Http::fake(['unipile.test/v2/acc_ben/users/me/posts*' => Http::response(benPosts())]);
    $collector = app(UnipileLinkedInMetricsCollector::class);
    $publication = fn (string $remoteId): AnalyticsPublication => AnalyticsPublication::factory()->create([
        'workspace_id' => $this->benWs->id, 'social_account_id' => $this->unipileChannel->id, 'social_account_key' => $this->unipileChannel->id,
        'platform' => Platform::UnipileLinkedIn, 'network' => 'linkedin', 'platform_user_id' => 'acc_ben',
        'remote_id' => $remoteId, 'provider_published_at' => CarbonImmutable::parse('2026-11-20 08:30', 'UTC'),
    ]);

    $engine = $collector->collect($publication('7400'), CarbonImmutable::parse('2026-11-25'));
    $own = $collector->collect($publication('7399'), CarbonImmutable::parse('2026-11-25'));

    $values = fn ($observation): array => collect($observation->metrics)->mapWithKeys(fn ($m): array => [$m->key->value => $m->value])->all();
    expect($values($engine))->toMatchArray([
        MetricKey::Impressions->value => 1500, MetricKey::Reactions->value => 38, MetricKey::Comments->value => 10,
        MetricKey::Shares->value => 2, MetricKey::Engagements->value => 50,
    ])
        ->and($values($own))->not->toHaveKey(MetricKey::Impressions->value)
        ->and($values($own))->toMatchArray([MetricKey::Reactions->value => 5, MetricKey::Comments->value => 1]);
    Http::assertSentCount(1);
});

it('reads Adi\'s OAuth LinkedIn through his matched Unipile account and links the post the Engine published', function () {
    MemberProfile::query()->create(['user_id' => $this->adi->id, 'unipile_account_id' => 'acc_adi', 'unipile_confirmed_at' => now()]);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->adiWs->id]);
    enginePost($channel, 'urn:li:share:7000000000000000009', ['published_at' => CarbonImmutable::parse('2026-11-24 08:30', 'UTC')]);
    Http::fake([
        'unipile.test/v2/acc_adi/users/me/posts*' => Http::response(['data' => [[
            'id' => 'u_7401', 'created_at' => '2026-11-24T08:30:05.000Z', 'text' => 'Shared through LinkedIn\'s own API.',
            'reactions_counter' => [['reaction' => 'like', 'count' => 9]], 'comments_counter' => 3, 'reposts_counter' => 1,
        ]]]),
        'unipile.test/v2/acc_adi/posts/*' => Http::response(['id' => 'u_7401']),
    ]);

    $page = app(UnipileLinkedInPublicationCollector::class)->page($channel, null, CarbonImmutable::parse('2025-11-25'));

    expect($page->publications[0]->providerPostId)->toBe('urn:li:share:7000000000000000009');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/acc_adi/posts/share'));
});

it('says no Unipile account is matched for an OAuth LinkedIn profile without one, and calls nobody', function () {
    Http::fake();
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->adiWs->id]);

    expect(fn () => app(UnipileLinkedInFollowerCollector::class)->collect($channel, CarbonImmutable::parse('2026-11-25')))
        ->toThrow(AnalyticsCollectionException::class, 'no Unipile account matched for this LinkedIn profile');
    expect(fn () => app(UnipileLinkedInPublicationCollector::class)->page($channel, null, CarbonImmutable::parse('2025-11-25')))
        ->toThrow(AnalyticsCollectionException::class, 'no Unipile account matched for this LinkedIn profile');
    Http::assertNothingSent();
});

it('never reads a missing Unipile account as a deleted post', function () {
    Http::fake(['unipile.test/v2/acc_ben/users/me/posts*' => Http::response(['title' => 'Not found'], 404)]);

    try {
        app(UnipileLinkedInPublicationCollector::class)->page($this->unipileChannel, null, CarbonImmutable::parse('2025-11-25'));
        $this->fail('expected an analytics exception');
    } catch (AnalyticsCollectionException $e) {
        expect($e->category)->toBe('permission')->and($e->gone)->toBeFalse();
    }
});

it('adds no calendar posts when a LinkedIn sync records its publications', function () {
    Bus::fake();
    Http::fake(['unipile.test/v2/acc_ben/users/me/posts*' => Http::response(benPosts())]);
    $engine = enginePost($this->unipileChannel, '7400');
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $this->unipileChannel->id,
        'collector' => SyncCollector::PublicationBackfill,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::parse('2025-11-25', 'UTC'),
    ]);
    $posts = Post::query()->count();

    app()->call([new BackfillAccountPublications($this->unipileChannel->id, $state->id), 'handle']);

    expect(AnalyticsPublication::query()->where('social_account_id', $this->unipileChannel->id)->pluck('remote_id')->sort()->values()->all())->toBe(['7399', '7400'])
        ->and(AnalyticsPublication::query()->where('remote_id', '7400')->value('post_id'))->toBe($engine->id)
        ->and(AnalyticsPublication::query()->where('remote_id', '7399')->value('post_id'))->toBeNull()
        ->and(Post::query()->count())->toBe($posts);
});

it('reads the Tantra page through Zernio: followers, posts and post numbers', function () {
    $tantraWs = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    $page = SocialAccount::factory()->create(['workspace_id' => $tantraWs->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => 'zacc', 'access_token' => 'zernio-managed']);
    Http::fake([
        // The first matching fake wins: the analytics path before the account list.
        'zernio.test/api/v1/accounts/zacc/linkedin-post-analytics*' => Http::response(['analytics' => [
            'impressions' => 1000, 'reach' => 800, 'likes' => 40, 'comments' => 8, 'shares' => 2, 'clicks' => 15,
        ]]),
        'zernio.test/api/v1/accounts*' => Http::response(['accounts' => [['_id' => 'zacc', 'platform' => 'linkedin', 'followersCount' => 8814]]]),
        'zernio.test/api/v1/posts?*' => Http::response([
            'posts' => [
                ['_id' => 'zp_1', 'content' => 'The page post.', 'status' => 'published', 'mediaItems' => [['type' => 'document', 'url' => 'https://cdn.test/deck.pdf']],
                    'platforms' => [['platform' => 'linkedin', 'accountId' => ['_id' => 'zacc'], 'status' => 'published', 'publishedAt' => '2026-11-24T09:00:00Z',
                        'platformPostUrl' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7526176630860431360/']]],
                ['_id' => 'zp_other', 'content' => 'Another account.', 'status' => 'published',
                    'platforms' => [['platform' => 'linkedin', 'accountId' => 'someone_else', 'publishedAt' => '2026-11-24T09:00:00Z']]],
            ],
            'pagination' => ['page' => 1, 'pages' => 1],
        ]),
    ]);

    expect(app(ZernioPageFollowerCollector::class)->collect($page, CarbonImmutable::parse('2026-11-25'))->followers)->toBe(8814);

    $list = app(ZernioPagePublicationCollector::class)->page($page, null, CarbonImmutable::parse('2025-11-25'));
    expect(array_map(fn (DiscoveredPublication $p): string => $p->providerPostId, $list->publications))->toBe(['zp_1'])
        ->and($list->publications[0]->contentType)->toBe(PublicationContentType::Carousel)
        ->and($list->providerExhausted)->toBeTrue();

    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $tantraWs->id, 'social_account_id' => $page->id, 'social_account_key' => $page->id,
        'platform' => Platform::ZernioLinkedInPage, 'network' => 'linkedin', 'platform_user_id' => 'zacc',
        'remote_id' => 'zp_1', 'permalink' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7526176630860431360/',
    ]);
    $values = collect(app(ZernioPageMetricsCollector::class)->collect($publication, CarbonImmutable::parse('2026-11-25'))->metrics)
        ->mapWithKeys(fn ($m): array => [$m->key->value => $m->value])->all();

    expect($values)->toMatchArray(['impressions' => 1000, 'reach' => 800, 'reactions' => 40, 'comments' => 8, 'shares' => 2, 'clicks' => 15, 'engagements' => 50]);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'linkedin-post-analytics') && $r['urn'] === 'urn:li:ugcPost:7526176630860431360');
});

it('opens the Insights page for every LinkedIn channel', function () {
    $oauth = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->adiWs->id]);
    $page = SocialAccount::factory()->create(['workspace_id' => $this->adiWs->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => 'zacc', 'access_token' => 'zernio-managed']);
    $unipile = SocialAccount::factory()->create(['workspace_id' => $this->adiWs->id, 'platform' => Platform::UnipileLinkedIn, 'platform_user_id' => 'acc_adi', 'access_token' => 'unipile-managed']);

    foreach ([$oauth, $page, $unipile] as $channel) {
        $this->actingAs($this->adi)
            ->get(route('app.channels.insights', $channel))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('channels/Insights')
                ->where('supported', true)
                ->where('channel.id', $channel->id)
                ->has('report')
                ->has('publications')
                ->etc());
    }

    $this->actingAs($this->adi)
        ->get(route('app.insights'))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('insights/Index')
            ->where('channelOptions', fn ($options): bool => collect($options)->pluck('id')->intersect([$oauth->id, $page->id, $unipile->id])->count() === 3)
            ->etc());
});

it('reads a Unipile channel\'s post through its own account in the twice daily pass', function () {
    $post = enginePost($this->unipileChannel, '7400', ['published_at' => now()->subDays(2)]);
    Http::fake([
        'unipile.test/v2/acc_ben/posts/7400' => Http::response([
            'id' => '7400', 'reactions_counter' => [['reaction' => 'like', 'count' => 36]], 'comments_counter' => 10, 'reposts_counter' => 2,
            'analytics' => ['impressions_counter' => 900],
        ]),
        'zernio.test/*' => Http::response([], 404),
    ]);

    CollectMetrics::run(app(ZernioClient::class), app(UnipileClient::class));

    $snapshot = MetricSnapshot::query()->where('post_id', $post->id)->sole();
    expect($snapshot->source)->toBe('unipile')
        ->and($snapshot->impressions)->toBe(900)
        ->and($snapshot->reactions)->toBe(36)
        ->and($snapshot->comments)->toBe(10);
});
