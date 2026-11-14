<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Links\TrackedLink;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// tantra: the traffic and lead tracking module on /tantra/insights.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));
    Cache::flush();

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];
    WorkspaceSettings::query()->create(['workspace_id' => $this->adi->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);

    $mate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->ben = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $mate->id, 'name' => 'Ben']);
    WorkspaceSettings::query()->create(['workspace_id' => $this->ben->id, 'brand_profile_key' => 'ben', 'review_owner_user_id' => $mate->id]);

    // Adi: an OAuth LinkedIn profile (likes and comments only). Ben: Unipile LinkedIn (impressions too).
    $adiLi = SocialAccount::factory()->create(['workspace_id' => $this->adi->id, 'platform' => Platform::LinkedIn]);
    $benLi = SocialAccount::factory()->create(['workspace_id' => $this->ben->id, 'platform' => Platform::UnipileLinkedIn]);

    $pub = fn (Workspace $w, SocialAccount $a, string $at, array $n) => AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => AnalyticsPublication::factory()->create(['workspace_id' => $w->id, 'social_account_id' => $a->id, 'platform' => $a->platform, 'network' => $a->platform->network(), 'provider_published_at' => $at])->id,
        'date' => '2026-11-13', ...$n,
    ]);
    $pub($this->adi, $adiLi, '2026-11-10 09:00:00', ['reactions_count' => 40, 'comments_count' => 12]);
    $pub($this->ben, $benLi, '2026-11-11 09:00:00', ['impressions_count' => 3000, 'reactions_count' => 30, 'comments_count' => 5, 'shares_count' => 2]);
    $pub($this->ben, $benLi, '2026-11-12 09:00:00', ['impressions_count' => 1000, 'reactions_count' => 10, 'comments_count' => 1, 'shares_count' => 0]);
    // Out of the 7 day range.
    $pub($this->ben, $benLi, '2026-10-01 09:00:00', ['impressions_count' => 99999, 'reactions_count' => 999]);
    foreach (['2026-11-09' => 1100, '2026-11-14' => 1135] as $date => $count) {
        AnalyticsAccountDailySnapshot::factory()->create(['workspace_id' => $this->ben->id, 'social_account_id' => $benLi->id, 'platform' => Platform::UnipileLinkedIn, 'network' => 'linkedin', 'date' => $date, 'followers_count' => $count]);
    }

    TrackedLink::query()->create(['workspace_id' => $this->ben->id, 'member_key' => 'ben', 'piece_ref' => 'ri:x', 'piece_name' => 'Count every sign-up once', 'platform' => 'linkedin',
        'placement' => 'comment', 'medium' => 'comment', 'code' => 'ben-count-sign-up-comment', 'destination' => 'https://jointantra.com/',
        'short_url' => 'https://go.usetantra.com/l/ben-count-sign-up-comment', 'long_url' => 'https://jointantra.com/?utm_content=ben-count-sign-up-comment',
        'shortened' => true, 'crm_link_id' => 'lnk_1', 'crm_asset_id' => 'ast_ben1', 'crm_channel_id' => 'chn_c1118abc4dc74c06a10b']);
});

function fakeReports(): void
{
    Http::fake(function (Request $r) {
        $path = parse_url($r->url(), PHP_URL_PATH);
        if ($path === '/api/trk/links') {
            return Http::response(['links' => [
                ['id' => 'lnk_1', 'code' => 'ben-count-sign-up-comment', 'clicks' => ['raw' => 9, 'human' => 7]],
                ['id' => 'lnk_p1', 'code' => 'adi-fw', 'clicks' => ['raw' => 20, 'human' => 15]],
                ['id' => 'lnk_p2', 'code' => 'adi-fc', 'clicks' => ['raw' => 5, 'human' => 4]],
            ]]);
        }
        $row = ['clicks_raw' => 9, 'clicks_human' => 7, 'visitors' => 6, 'registrations' => 3, 'calls_booked' => 1, 'leads' => null, 'deals_won' => 1, 'revenue_attributed' => 49900];

        return match ($r['group_by'][0] ?? null) {
            'asset' => Http::response(['rows' => [['asset' => 'ast_ben1', ...$row, 'visitors' => null], ['asset' => 'ast_other', ...$row]], 'totals' => $row, 'currency' => 'USD', 'generated_at' => '2026-11-14T12:00:00Z']),
            'channel' => Http::response(['rows' => [['channel' => 'linkedin', ...$row], ['channel' => 'calendar', ...$row]], 'totals' => $row]),
            'day' => Http::response(['rows' => [['day' => '2026-11-12', ...$row], ['day' => '2026-11-13', ...$row, 'clicks_raw' => 4]], 'totals' => [...$row, 'visitors' => 11]]),
            default => Http::response([], 404),
        };
    });
}

it('shows each person traffic from the CRM and social from their platforms, with nulls for what is not given', function () {
    config(['tantra.crm.api_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    fakeReports();

    $this->actingAs($this->owner)->get('/tantra/insights?days=7&bucket=week')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/insights/Index')
            ->where('traffic.crm.status', 'live')
            ->where('traffic.range.from', '2026-11-08')
            ->where('traffic.range.bucket', 'week')
            ->where('traffic.members.0.key', 'adi')
            ->where('traffic.members.0.social.posts', 1)
            ->where('traffic.members.0.social.likes', 40)
            ->where('traffic.members.0.social.impressions', null)
            ->where('traffic.members.0.social_notes.impressions', 'LinkedIn personal profiles give only likes and comments per post through the API.')
            ->where('traffic.members.0.traffic.clicks', 0)
            ->where('traffic.members.0.profile_clicks', ['links' => 2, 'clicks' => 25, 'clicks_human' => 19])
            ->where('traffic.members.1.key', 'ben')
            ->where('traffic.members.1.social.posts', 2)
            ->where('traffic.members.1.social.impressions', 4000)
            ->where('traffic.members.1.social.shares', 2)
            ->where('traffic.members.1.social.followers', 1135)
            ->where('traffic.members.1.social.followers_gained', 35)
            ->where('traffic.members.1.traffic.clicks', 9)
            ->where('traffic.members.1.traffic.conversions', 4)
            ->where('traffic.members.1.traffic.sales', 1)
            ->where('traffic.members.1.traffic.revenue', 499)
            ->where('traffic.members.1.traffic.visits', null)
            ->where('traffic.members.1.traffic.leads', null)
            ->where('traffic.totals.traffic.visits', 12)
            ->where('traffic.pieces.0.piece', 'Count every sign-up once')
            ->where('traffic.pieces.0.clicks', 9)
            ->where('traffic.pieces.0.visits', null)
            ->where('traffic.pieces.0.links.0.url', 'https://go.usetantra.com/l/ben-count-sign-up-comment')
            // Per platform from this campaign's assets only (the other asset is not ours), visits from the channel rows.
            ->where('traffic.platforms', fn ($rows) => collect($rows)->firstWhere(fn ($r) => $r['member'] === '_team' && $r['platform'] === 'linkedin')['clicks'] === 9
                && collect($rows)->firstWhere(fn ($r) => $r['member'] === '_team' && $r['platform'] === 'linkedin')['visits'] === 6
                && ! collect($rows)->contains(fn ($r) => $r['platform'] === 'calendar'))
            ->where('traffic.series', fn ($series) => collect($series)->where('member', '_team')->sum('clicks') === 13
                && collect($series)->where('member', 'ben')->sum('posts') === 2)
        );

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/trk/reports/query')
        && $r['group_by'] === ['asset'] && $r['filters']['campaign_ids'] === ['cmp_f427ef8e47514047be1d']
        && $r['range'] === ['from' => '2026-11-08', 'to' => '2026-11-14'] && $r->hasHeader('Authorization', 'Bearer k'));
});

it('keeps social numbers and says why traffic is missing when the CRM key is not set', function () {
    config(['tantra.crm.api_key' => null]);
    Http::fake();

    $this->actingAs($this->owner)->get('/tantra/insights?days=30')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('traffic.crm.status', 'not_configured')
            ->where('traffic.crm.message', fn (string $m) => str_contains($m, 'TANTRA_CRM_API_KEY'))
            ->where('traffic.members.1.social.impressions', 4000)
            ->where('traffic.members.1.traffic.clicks', null)
            ->where('traffic.pieces.0.clicks', null)
        );
    Http::assertNothingSent();
});

it('shows the CRM error instead of zeros when the report does not answer', function () {
    config(['tantra.crm.api_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    Http::fake(['crm.test/*' => Http::response(['error' => 'down'], 503)]);

    $this->actingAs($this->owner)->get('/tantra/insights')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('traffic.crm.status', 'error')
            ->where('traffic.crm.message', fn (string $m) => str_contains($m, 'HTTP 503'))
            ->where('traffic.members.1.traffic.clicks', null)
        );
});

it('never sends the CRM key to the browser', function () {
    config(['tantra.crm.api_key' => 'secret-crm-key', 'tantra.crm.url' => 'https://crm.test']);
    fakeReports();

    $html = $this->actingAs($this->owner)->get('/tantra/insights')->assertOk()->getContent();
    expect($html)->not->toContain('secret-crm-key');
});
