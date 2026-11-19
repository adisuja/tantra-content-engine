<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

// tantra: the Tantra app's Content > Insights and Content > Settings, read only, server to server (docs/crm/engine-patches/p4-c07b).

beforeEach(function () {
    Cache::flush();
    $this->owner = User::factory()->create(['name' => 'Adi Murali', 'email' => 'adi@example.test']);
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Tantra']);
    $this->second = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Aquila']);
    $this->asker = workspaceMember($this->team, 'approval', ['email' => 'ann@example.test', 'name' => 'Ann Asker']);
    $this->direct = workspaceMember($this->team, 'member', ['email' => 'dana@example.test', 'name' => 'Dana Direct']);
    $stranger = User::factory()->create(['email' => 'stan@example.test', 'name' => 'Stan Stranger']);
    $this->other = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id, 'name' => 'Not ours']);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::LinkedIn, 'status' => Status::Connected]);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::X, 'status' => Status::Connected]);
    $this->theirs = SocialAccount::factory()->create(['workspace_id' => $this->other->id, 'platform' => Platform::LinkedIn, 'status' => Status::Connected]);

    config([
        'tantra.engagement_channels.key' => 'crm-key',
        'tantra.engagement_channels.workspaces' => "ws_team={$this->team->id}|{$this->second->id},ws_other={$this->other->id}",
    ]);
});

function crmIns(object $test, string $path, array $body, ?string $key = 'crm-key'): TestResponse
{
    return $test->withHeaders(array_filter(['Authorization' => $key === null ? null : "Bearer {$key}", 'X-Tantra-Client' => 'crm-content']))->postJson("/api/tantra/engagement/{$path}", $body);
}

/** A published post with one analytics reading; a null impressions count stays null. */
function crmInsPost(object $test, SocialAccount $account, array $over = [], array $metrics = ['impressions_count' => 1000, 'reactions_count' => 40, 'comments_count' => 5, 'shares_count' => 5]): Post
{
    $post = Post::factory()->forAccount($account)->create(['status' => PostStatus::Published, 'content' => 'A post', 'published_at' => now()->subDays(2), 'platform_url' => 'https://example.test/p', ...$over]);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $account->workspace_id, 'post_id' => $post->id, 'platform' => $account->platform, 'network' => $account->platform->network()]);
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => today(), ...$metrics]);

    return $post;
}

it('answers 401 to a missing or wrong key on both', function () {
    crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30], null)->assertUnauthorized();
    crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30], 'nope')->assertUnauthorized();
    crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'adi@example.test'], null)->assertUnauthorized();
    crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'adi@example.test'], 'nope')->assertUnauthorized();
});

it('counts the numbers of the mapped team only', function () {
    crmInsPost($this, $this->linkedin);
    crmInsPost($this, $this->x, ['content' => 'On X'], ['impressions_count' => 500, 'engagement_count' => 20]);
    crmInsPost($this, $this->theirs, ['content' => 'Not ours'], ['impressions_count' => 99999, 'engagement_count' => 9999]);
    Post::factory()->forAccount($this->linkedin)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->subHours(3)]);
    Post::factory()->forAccount($this->linkedin)->create(['status' => PostStatus::Failed, 'content' => 'Broke']);

    $r = crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($r->json('data.totals'))->toBe(['posts' => 4, 'published' => 2, 'scheduled' => 1, 'failed' => 1, 'impressions' => 1500, 'engagements' => 70, 'clicks' => null, 'measured' => 2])
        ->and(collect($r->json('data.by_network'))->pluck('platform')->sort()->values()->all())->toBe(['linkedin', 'x'])
        ->and(collect($r->json('data.top'))->pluck('content')->all())->toBe(['A post', 'On X'])
        ->and($r->json('data.updated_at'))->not->toBeNull();
    expect(collect(crmIns($this, 'insights', ['workspace' => 'ws_other', 'days' => 30])->json('data.top'))->pluck('content')->all())->toBe(['Not ours']);
});

it('honours days and leaves a post outside the window out', function () {
    crmInsPost($this, $this->linkedin, ['content' => 'Recent', 'published_at' => now()->subDays(3)]);
    crmInsPost($this, $this->linkedin, ['content' => 'Last month', 'published_at' => now()->subDays(20)]);
    crmInsPost($this, $this->linkedin, ['content' => 'Old', 'published_at' => now()->subDays(60)]);

    expect(crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 7])->json('data.totals.published'))->toBe(1)
        ->and(crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->json('data.totals.published'))->toBe(2)
        ->and(crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 90])->json('data.totals.published'))->toBe(3);
});

it('keeps null impressions null, never 0', function () {
    crmInsPost($this, $this->x, [], ['impressions_count' => null, 'engagement_count' => 12]);

    $r = crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->assertOk();
    expect($r->json('data.totals.impressions'))->toBeNull()
        ->and($r->json('data.totals.engagements'))->toBe(12)
        ->and($r->json('data.by_network.0.impressions'))->toBeNull()
        ->and($r->json('data.top.0.impressions'))->toBeNull();
});

it('caps top at 10, best engagements first then impressions, and only https links', function () {
    foreach (range(1, 12) as $n) {
        crmInsPost($this, $this->linkedin, ['content' => "Post {$n}"], ['impressions_count' => 100 * $n, 'engagement_count' => $n]);
    }
    crmInsPost($this, $this->linkedin, ['content' => 'Tie, more impressions', 'platform_url' => 'http://insecure.test/p'], ['impressions_count' => 5000, 'engagement_count' => 12]);

    $top = crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->assertOk()->json('data.top');
    expect($top)->toHaveCount(10)
        ->and($top[0]['content'])->toBe('Tie, more impressions')
        ->and($top[0]['platform_url'])->toBeNull()
        ->and($top[1]['content'])->toBe('Post 12')
        ->and(collect($top)->pluck('engagements')->all())->toBe([12, 12, 11, 10, 9, 8, 7, 6, 5, 4])
        ->and($top[1]['platform_url'])->toBe('https://example.test/p');
});

it('refuses a days value that is not 7, 30 or 90', function () {
    crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 5])->assertStatus(422);
    crmIns($this, 'insights', ['workspace' => 'ws_team'])->assertStatus(422);
});

it('gives an unmapped team the empty answer', function () {
    crmInsPost($this, $this->linkedin);

    $r = crmIns($this, 'insights', ['workspace' => 'ws_unknown', 'days' => 30])->assertOk();
    expect($r->json('data.by_network'))->toBe([])->and($r->json('data.top'))->toBe([])->and($r->json('data.updated_at'))->toBeNull()
        ->and(collect($r->json('data.totals'))->filter(fn ($v) => $v !== null))->toBeEmpty();
});

it('tells a mapped team with nothing yet from an unmapped one (ci-insights)', function () {
    expect(crmIns($this, 'insights', ['workspace' => 'ws_unknown', 'days' => 30])->json('data.linked'))->toBeFalse();
    $r = crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->assertOk();
    expect($r->json('data.linked'))->toBeTrue()
        ->and($r->json('data.totals.published'))->toBe(0)
        ->and($r->json('data.totals.measured'))->toBe(0)
        ->and($r->json('data.by_profile'))->toHaveCount(2);
});

it('counts views as impressions where a network has no impressions, reads link clicks, and counts posts with a reading (ci-insights)', function () {
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->team->id, 'platform' => Platform::Instagram, 'status' => Status::Connected]);
    crmInsPost($this, $instagram, ['content' => 'A reel'], ['impressions_count' => null, 'exposure_count' => 2400, 'exposure_kind' => 'views', 'engagement_count' => 60]);
    crmInsPost($this, $this->x, ['content' => 'A thread'], ['impressions_count' => 900, 'engagement_count' => 30, 'metrics' => ['link_clicks' => ['value' => 14, 'availability' => 'available']]]);
    Post::factory()->forAccount($this->linkedin)->create(['status' => PostStatus::Published, 'content' => 'Not read yet', 'published_at' => now()->subDay()]);

    $r = crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->assertOk();
    $byNetwork = collect($r->json('data.by_network'))->keyBy('platform');
    expect($r->json('data.totals.impressions'))->toBe(3300)
        ->and($r->json('data.totals.clicks'))->toBe(14)
        ->and($r->json('data.totals.published'))->toBe(3)
        ->and($r->json('data.totals.measured'))->toBe(2)
        ->and($byNetwork['instagram']['impressions'])->toBe(2400)
        ->and($byNetwork['instagram']['clicks'])->toBeNull()
        ->and($byNetwork['linkedin']['measured'])->toBe(0)
        ->and(collect($r->json('data.top'))->every(fn (array $p): bool => ! array_key_exists('measured', $p) && ! array_key_exists('account', $p)))->toBeTrue();
});

it('puts no token, meta or workspace id in the insights JSON', function () {
    crmInsPost($this, $this->linkedin);

    $body = crmIns($this, 'insights', ['workspace' => 'ws_team', 'days' => 30])->assertOk()->getContent();
    expect($body)->not->toContain($this->team->id)->not->toContain('access_token')->not->toContain('refresh_token')->not->toContain('meta')->not->toContain('workspace_id')->not->toContain('social_account');
});

it('lists the workspaces of the mapped team only, with members by name and role and nothing else', function () {
    $r = crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'adi@example.test'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $rows = collect($r->json('data.workspaces'));
    expect($rows->pluck('name')->all())->toBe(['Aquila', 'Tantra'])
        ->and($rows->firstWhere('name', 'Tantra')['channels_count'])->toBe(2)
        ->and($rows->firstWhere('name', 'Tantra'))->toHaveKeys(['id', 'name', 'timezone', 'channels_count', 'approval_required', 'members']);
    $members = collect($rows->firstWhere('name', 'Tantra')['members']);
    expect($members->pluck('name')->all())->toContain('Adi Murali', 'Ann Asker', 'Dana Direct')->not->toContain('Stan Stranger')
        ->and($members->every(fn (array $m): bool => array_keys($m) === ['name', 'role']))->toBeTrue()
        ->and($members->firstWhere('name', 'Adi Murali')['role'])->toBe('owner');
    expect($r->getContent())->not->toContain('@example.test')->not->toContain('access_token');
});

it('says approval is required for an approval person and not for an owner', function () {
    $ann = crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'ann@example.test'])->assertOk();
    expect($ann->json('data.you.needs_approval'))->toBeTrue()
        ->and(collect($ann->json('data.workspaces'))->firstWhere('name', 'Tantra')['approval_required'])->toBeTrue();
    $adi = crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'adi@example.test'])->assertOk();
    expect($adi->json('data.you'))->toBe(['role' => 'owner', 'needs_approval' => false])
        ->and(collect($adi->json('data.workspaces'))->pluck('approval_required')->all())->each->toBeFalse();
});

it('answers 403 unknown_person to an email it does not know', function () {
    crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'nobody@example.test'])->assertForbidden()->assertJsonPath('error', 'unknown_person');
    crmIns($this, 'settings', ['workspace' => 'ws_team', 'actor_email' => 'stan@example.test'])->assertForbidden();
});

it('gives an unmapped team the empty settings answer', function () {
    crmIns($this, 'settings', ['workspace' => 'ws_unknown', 'actor_email' => 'adi@example.test'])->assertOk()->assertExactJson(['data' => ['workspaces' => [], 'you' => null]]);
});
