<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Dto\Analytics\DateRange;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Publications\XPublicationCollector;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Analytics\InsightsScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create(['timezone' => 'UTC', 'name' => 'Adi']);
    $this->workspace = fn (string $name): Workspace => tap(
        Workspace::factory()->create(['name' => $name, 'account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]),
        fn (Workspace $space) => $space->members()->attach($this->owner->id, membershipPivot('admin')),
    );
    $this->adiWs = ($this->workspace)('Adi');
    $this->nayanaWs = ($this->workspace)('Nayana');
    $this->owner->update(['current_workspace_id' => $this->adiWs->id]);

    $this->adiX = insightsChannel($this->adiWs, Platform::X, 'Adi on X');
    $this->nayana = insightsChannel($this->nayanaWs, Platform::UnipileLinkedIn, 'Nayana Kumar');
});

function insightsChannel(Workspace $workspace, Platform $platform, string $name): SocialAccount
{
    return SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'display_name' => $name,
        'username' => str($name)->slug()->toString(),
    ]);
}

/** @param  array<string, int|null>  $metrics */
function insightsPost(SocialAccount $channel, array $metrics, string $publishedAt = '-3 days', array $attributes = [], ?string $date = null): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'social_account_id' => $channel->id,
        'social_account_key' => $channel->id,
        'network' => $channel->platform->network(),
        'platform_user_id' => $channel->platform_user_id,
        'platform' => $channel->platform,
        'account_display_name' => $channel->display_name,
        'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
        ...$attributes,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => $date ?? now('UTC')->toDateString(),
        ...$metrics,
    ]);

    return $publication;
}

function insightsFollowers(SocialAccount $channel, string $date, int $count): void
{
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'social_account_id' => $channel->id,
        'social_account_key' => $channel->id,
        'platform' => $channel->platform,
        'network' => $channel->platform->network(),
        'platform_user_id' => $channel->platform_user_id,
        'date' => $date,
        'followers_count' => $count,
    ]);
}

it('shows the Account owner every workspace of the Account', function () {
    $mine = insightsPost($this->adiX, ['reactions_count' => 4, 'comments_count' => 1]);
    $hers = insightsPost($this->nayana, ['reactions_count' => 40, 'comments_count' => 9, 'impressions_count' => 2000], attributes: ['origin' => PublicationOrigin::External]);
    insightsFollowers($this->nayana, now('UTC')->toDateString(), 12462);
    $foreign = insightsChannel(Workspace::factory()->create(), Platform::X, 'Someone else');
    insightsPost($foreign, ['reactions_count' => 999]);

    $this->actingAs($this->owner)->get(route('app.insights'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('insights/Index')
            ->has('channelOptions', 2)
            ->where('report.summary.posts.value', 2)
            ->where('report.top_posts.reactions.0.id', $hers->id)
            ->has('report.performance', 2)
            ->where('tantraInsights.top_posts.engagement.0.id', $hers->id)
            ->where('tantraInsights.top_posts.engagement.0.person', 'Nayana')
            ->where('tantraInsights.top_posts.engagement.1.id', $mine->id)
            ->has('tantraInsights.workspaces', 2)
            ->has('tantraInsights.channels', 2)
            ->where('tantraInsights.posts.total', 2)
            ->etc());
});

it('shows a member only the workspaces they belong to', function () {
    insightsPost($this->adiX, ['reactions_count' => 4]);
    $hers = insightsPost($this->nayana, ['reactions_count' => 40]);
    $member = workspaceMember($this->nayanaWs);

    $this->actingAs($member)->get(route('app.insights'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('channelOptions', 1)
            ->where('channelOptions.0.id', $this->nayana->id)
            ->where('report.summary.posts.value', 1)
            ->has('tantraInsights.workspaces', 1)
            ->has('tantraInsights.channels', 1)
            ->where('tantraInsights.posts.data.0.id', $hers->id)
            ->where('tantraInsights.posts.total', 1)
            ->etc());
});

it('narrows every section by person, network and channel', function () {
    $adiLinkedIn = insightsChannel($this->adiWs, Platform::UnipileLinkedIn, 'Adi on LinkedIn');
    $x = insightsPost($this->adiX, ['reactions_count' => 4]);
    $adiPost = insightsPost($adiLinkedIn, ['reactions_count' => 8]);
    $nayanaPost = insightsPost($this->nayana, ['reactions_count' => 40]);

    $this->actingAs($this->owner)->get(route('app.insights', ['workspaces' => [$this->nayanaWs->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 1)
            ->where('report.filters.channels', [])
            ->where('tantraInsights.filters.workspaces', [$this->nayanaWs->id])
            ->where('tantraInsights.filtered', true)
            ->where('tantraInsights.posts.data.0.id', $nayanaPost->id)
            ->has('tantraInsights.channels', 1)
            ->etc());

    $this->actingAs($this->owner)->get(route('app.insights', ['networks' => ['linkedin']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 2)
            ->where('tantraInsights.top_posts.reactions.0.id', $nayanaPost->id)
            ->where('tantraInsights.top_posts.reactions.1.id', $adiPost->id)
            ->has('tantraInsights.top_posts.reactions', 2)
            ->etc());

    $this->actingAs($this->owner)->get(route('app.insights', ['networks' => ['linkedin'], 'workspaces' => [$this->adiWs->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 1)
            ->where('tantraInsights.posts.data.0.id', $adiPost->id)
            ->etc());

    $this->actingAs($this->owner)->get(route('app.insights', ['channels' => [$this->adiX->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', [$this->adiX->id])
            ->where('tantraInsights.posts.data.0.id', $x->id)
            ->where('tantraInsights.posts.total', 1)
            ->etc());

    // A person with no channel on the network shows nothing, never everything.
    $this->actingAs($this->owner)->get(route('app.insights', ['networks' => ['x'], 'workspaces' => [$this->nayanaWs->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.bounds.min', null)
            ->where('tantraInsights.posts.total', 0)
            ->etc());
});

it('ranks the Top 5 under each sort and sorts and pages the posts table', function () {
    $loud = insightsPost($this->nayana, ['reactions_count' => 50, 'comments_count' => 0, 'shares_count' => 0, 'impressions_count' => 10000]);
    $sharp = insightsPost($this->nayana, ['reactions_count' => 10, 'comments_count' => 20, 'shares_count' => 5, 'impressions_count' => 100]);
    foreach (range(1, 10) as $day) {
        insightsPost($this->adiX, ['reactions_count' => 1], "-{$day} hours");
    }

    $this->actingAs($this->owner)->get(route('app.insights'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tantraInsights.top_posts.engagement.0.id', $loud->id)
            ->where('tantraInsights.top_posts.engagement.0.engagement', 50)
            ->where('tantraInsights.top_posts.engagement.1.id', $sharp->id)
            ->where('tantraInsights.top_posts.impressions.0.id', $loud->id)
            ->where('tantraInsights.top_posts.comments.0.id', $sharp->id)
            ->where('tantraInsights.top_posts.shares.0.id', $sharp->id)
            ->where('tantraInsights.top_posts.engagement_rate.0.id', $sharp->id)
            ->where('tantraInsights.top_posts.engagement_rate.0.engagement_rate', 35.0)
            ->has('tantraInsights.top_posts.engagement', 5)
            ->where('tantraInsights.posts.sort', 'published_at')
            ->where('tantraInsights.posts.total', 12)
            ->where('tantraInsights.posts.last_page', 2)
            ->has('tantraInsights.posts.data', 10)
            ->etc());

    $this->actingAs($this->owner)->get(route('app.insights', ['posts_sort' => 'impressions', 'posts_dir' => 'asc', 'posts_page' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tantraInsights.posts.sort', 'impressions')
            ->where('tantraInsights.posts.direction', 'asc')
            ->where('tantraInsights.posts.data.0.id', $sharp->id)
            ->where('tantraInsights.posts.data.1.id', $loud->id)
            ->etc());

    $this->actingAs($this->owner)->get(route('app.insights', ['posts_sort' => 'comments', 'posts_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tantraInsights.posts.current_page', 2)
            ->has('tantraInsights.posts.data', 2)
            ->etc());
});

it('lists external posts in the table, not only the Engine\'s own', function () {
    $external = insightsPost($this->nayana, ['reactions_count' => 3], attributes: ['origin' => PublicationOrigin::External, 'excerpt' => 'Written on LinkedIn']);

    $this->actingAs($this->owner)->get(route('app.insights'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tantraInsights.posts.data.0.id', $external->id)
            ->where('tantraInsights.posts.data.0.origin', 'external')
            ->where('tantraInsights.posts.data.0.excerpt', 'Written on LinkedIn')
            ->where('tantraInsights.posts.data.0.person', 'Nayana')
            ->etc());
});

it('reads a custom range by the day each post went out', function () {
    $inside = insightsPost($this->nayana, ['reactions_count' => 5], '2026-10-10 09:00:00', date: '2026-10-12');
    insightsPost($this->nayana, ['reactions_count' => 7], '2026-10-20 09:00:00', date: '2026-10-21');
    insightsFollowers($this->nayana, '2026-09-01', 100);

    $this->actingAs($this->owner)->get(route('app.insights', ['range' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-15']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.range', 'custom')
            ->where('report.range.start', '2026-10-01')
            ->where('report.range.end', '2026-10-15')
            ->where('report.summary.posts.value', 1)
            ->where('tantraInsights.posts.data.0.id', $inside->id)
            ->has('tantraInsights.posts.data', 1)
            ->etc());
});

it('takes a post\'s numbers from its latest reading on or before the period\'s end, else its latest one', function () {
    $publication = insightsPost($this->nayana, ['reactions_count' => 5], '2026-10-02 09:00:00', date: '2026-10-05');
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => '2026-10-20', 'reactions_count' => 50]);
    $late = insightsPost($this->nayana, ['reactions_count' => 9], '2026-10-09 09:00:00', date: '2026-10-25');

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute(
        $this->nayanaWs,
        new DateRange(CarbonImmutable::parse('2026-10-01', 'UTC'), CarbonImmutable::parse('2026-10-10', 'UTC')),
    );

    expect(collect($report['top_posts']['reactions'])->pluck('reactions', 'id')->all())
        ->toBe([$late->id => 9, $publication->id => 5]);
});

it('shows each channel\'s followers by name and person, a single day as its count', function () {
    insightsFollowers($this->nayana, now('UTC')->toDateString(), 12462);
    insightsFollowers($this->adiX, now('UTC')->subDays(5)->toDateString(), 700);
    insightsFollowers($this->adiX, now('UTC')->toDateString(), 715);

    $this->actingAs($this->owner)->get(route('app.insights'))
        ->assertInertia(function (Assert $page) {
            $channels = collect($page->toArray()['props']['tantraInsights']['channels'])->keyBy('id');

            expect($channels[$this->nayana->id]['person'])->toBe('Nayana')
                ->and($channels[$this->nayana->id]['display_label'])->toBe('Nayana Kumar')
                ->and($channels[$this->nayana->id]['platform'])->toBe('unipile-linkedin')
                ->and($channels[$this->nayana->id]['followers']['value'])->toBe(12462)
                ->and($channels[$this->nayana->id]['followers']['change'])->toBeNull()
                ->and($channels[$this->adiX->id]['person'])->toBe('Adi')
                ->and($channels[$this->adiX->id]['followers']['value'])->toBe(715)
                ->and($channels[$this->adiX->id]['followers']['change'])->toBe(15);
            $page->has('tantraInsights.channels', 2)->etc();
        });
});

it('says in plain words why a channel has no numbers', function () {
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($this->adiX),
        'social_account_id' => $this->adiX->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Failed,
        'last_error_category' => 'plan_required',
    ]);
    $page = insightsChannel($this->nayanaWs, Platform::ZernioLinkedInPage, 'Tantra');
    insightsPost($this->nayana, ['reactions_count' => 1], attributes: ['metric_failures' => 2]);

    $this->actingAs($this->owner)->get(route('app.insights'))
        ->assertInertia(function (Assert $inertia) use ($page) {
            $channels = collect($inertia->toArray()['props']['tantraInsights']['channels'])->keyBy('id');

            expect($channels[$this->adiX->id]['notices'])->toBe([['key' => 'plan_required']])
                ->and($channels[$this->nayana->id]['notices'])->toBe([['key' => 'metric_failures', 'count' => 1]])
                ->and(array_column($channels[$page->id]['notices'], 'key'))->toContain('engine_posts_only');
            $inertia->has('tantraInsights.channels', 3)->etc();
        });

    expect(__('tantra.insights_all.reasons.plan_required', ['network' => 'X']))
        ->toBe('X needs a paid API plan to read post numbers.');
});

it('reads X\'s answer for an app without a paid plan as plan_required, not malformed', function (int $status, array $body) {
    $account = SocialAccount::factory()->x()->create();
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => app(XPublicationCollector::class)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === 'plan_required');
})->with([
    'no credit' => [402, ['title' => 'CreditsDepleted', 'detail' => 'Your enrolled account does not have any credits to fulfill this request.', 'type' => 'https://api.twitter.com/2/problems/credits']],
    'not enrolled' => [403, ['title' => 'Client Forbidden', 'reason' => 'client-not-enrolled', 'type' => 'https://api.twitter.com/2/problems/client-forbidden']],
]);

it('keeps a plain 403 a permission problem', function () {
    $account = SocialAccount::factory()->x()->create();
    Http::fake(['*' => Http::response(['title' => 'Forbidden'], 403)]);

    expect(fn () => app(XPublicationCollector::class)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === 'permission');
});

it('tries a failed backfill again once a day, so a fixed cause recovers', function () {
    subscribeAccount($this->owner->account);
    $states = [];
    foreach (['old' => 30, 'fresh' => 2] as $label => $hours) {
        $channel = insightsChannel($this->adiWs, Platform::Instagram, "Instagram {$label}");
        $state = AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($channel),
            'social_account_id' => $channel->id,
            'collector' => SyncCollector::PublicationBackfill,
            'status' => SyncStatus::Failed,
            'last_error_category' => 'permission',
        ]);
        $state->updated_at = now()->subHours($hours);
        $state->saveQuietly();
        $states[$label] = $channel;
    }
    Bus::fake();

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $states['old']->id);
    Bus::assertNotDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $states['fresh']->id);
    Bus::assertNotDispatched(DiscoverAccountPublications::class);
});

it('backfills a Unipile channel as soon as the team sync creates it', function () {
    subscribeAccount($this->owner->account);
    config(['tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);
    $ben = User::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Ben Basil Tomy']);
    $benWs = ($this->workspace)('Ben');
    $benWs->members()->attach($ben->id, membershipPivot('admin'));
    MemberProfile::query()->create(['user_id' => $ben->id, 'unipile_account_id' => 'acc_ben', 'unipile_name' => 'Ben Basil Tomy']);
    Http::fake(['unipile.test/v2/accounts*' => Http::response(['data' => [
        ['id' => 'acc_ben', 'name' => 'Ben Basil Tomy', 'type' => 'LINKEDIN', 'status' => 'running'],
    ]])]);

    $this->artisan('tantra:sync-channels')->assertSuccessful();

    $channel = SocialAccount::query()->where('platform_user_id', 'acc_ben')->sole();
    Queue::assertPushed(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $channel->id);
});

it('leaves the channel page and other reads on their own workspace', function () {
    insightsPost($this->nayana, ['reactions_count' => 40]);
    InsightsScope::clear();

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute(
        $this->adiWs,
        new DateRange(now('UTC')->subDays(29)->startOfDay()->toImmutable(), now('UTC')->startOfDay()->toImmutable()),
    );

    expect($report['summary']['posts']['value'])->toBe(0)
        ->and(InsightsScope::ids($this->adiWs->id))->toBe([$this->adiWs->id]);
});
