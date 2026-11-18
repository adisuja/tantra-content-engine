<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Links\PieceLinks;
use App\Tantra\Links\TrackedLink;
use App\Tantra\Provision\TantraWorkspaceLink;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// tantra: tracked post links are made in the tracking of the team the post belongs to (audit 2026-11-18, root cause 3).
// Tantra's own workspaces keep their links in Tantra's own campaign; a provisioned team's links are made by Tantra in that
// team's own tracking (POST /intake/content/links with the intake key); a workspace no team owns is left alone.

function fakeTeamCrm(object $test): void
{
    $test->calls = [];
    $test->teamAnswer = null;
    Http::fake(function (Request $r) use ($test) {
        if (parse_url($r->url(), PHP_URL_HOST) !== 'crm.test') {
            return null;
        }
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $test->calls[] = ['method' => $r->method(), 'path' => $path, 'auth' => $r->header('Authorization')[0] ?? '', 'body' => $r->data()];
        if ($path === '/intake/content/links') {
            if ($test->teamAnswer !== null) {
                return ($test->teamAnswer)($r);
            }

            return Http::response(['short_url' => 'https://go.usetantra.com/l/'.$r['code'], 'long_url' => $r['destination'].'?utm_source=linkedin&utm_medium=organic_social&utm_campaign=content-posts&tlid=t1', 'code' => $r['code'], 'created' => true]);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/channels')) {
            return Http::response(['channel' => ['id' => 'chn_'.$r['channel']]], 201);
        }
        if ($r->method() === 'GET' && str_ends_with($path, '/assets')) {
            return Http::response(['assets' => []]);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/assets')) {
            return Http::response(['asset' => ['id' => 'ast_1']], 201);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/links')) {
            return Http::response(['id' => 'lnk_1', 'code' => $r['code'], 'short_url' => 'https://go.usetantra.com/l/'.$r['code'], 'long_url' => $r['destination']], 201);
        }

        return Http::response([], 404);
    });
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    Storage::fake('local');
    config(['tantra.crm.api_key' => 'trk-key', 'tantra.crm.intake_key' => 'intake-key', 'tantra.crm.url' => 'https://crm.test']);
    fakeTeamCrm($this);

    // The main Account (the first one): Tantra's own team.
    $this->tantraOwner = User::factory()->create();
    $this->tantraWs = Workspace::factory()->create(['account_id' => $this->tantraOwner->account_id, 'user_id' => $this->tantraOwner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->tantraWs->id, 'brand_profile_key' => 'sam']);

    // Another team, provisioned from Tantra: its own Account, mapped in tantra_workspace_links.
    $this->teamOwner = User::factory()->create();
    $this->teamWs = Workspace::factory()->create(['account_id' => $this->teamOwner->account_id, 'user_id' => $this->teamOwner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->teamWs->id, 'brand_profile_key' => 'river']);
    TantraWorkspaceLink::query()->create(['tantra_workspace_id' => 'ws_river_team', 'workspace_id' => $this->teamWs->id, 'account_id' => $this->teamOwner->account_id, 'name' => 'River Co']);

    // A workspace outside the main Account that no Tantra team owns.
    $this->loneOwner = User::factory()->create();
    $this->loneWs = Workspace::factory()->create(['account_id' => $this->loneOwner->account_id, 'user_id' => $this->loneOwner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->loneWs->id, 'brand_profile_key' => 'lone']);
});

function teamPost(Workspace $ws, User $by, string $content): Post
{
    return Post::factory()->create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'status' => 'draft', 'platform' => Platform::X, 'content' => $content])->refresh();
}

it('sorts workspaces by whose tracking their links go to', function () {
    expect(PieceLinks::teamOf($this->tantraWs->id))->toBeNull()
        ->and(PieceLinks::teamOf($this->teamWs->id))->toBe('ws_river_team')
        ->and(PieceLinks::teamOf($this->loneWs->id))->toBeFalse()
        ->and(PieceLinks::teamOf(null))->toBeNull();
});

it('asks Tantra for a provisioned team\'s link, in that team\'s own tracking, never with Tantra\'s own key or campaign', function () {
    $post = teamPost($this->teamWs, $this->teamOwner, 'Our spring guide is out: https://example.org/guide?utm_source=old');

    expect($post->content)->toBe('Our spring guide is out: https://go.usetantra.com/l/river-our-spring-guide-x-post');
    expect(collect($this->calls)->pluck('path')->unique()->values()->all())->toBe(['/intake/content/links']);
    $call = collect($this->calls)->sole();
    expect($call['auth'])->toBe('Bearer intake-key')
        ->and($call['body'])->toMatchArray([
            'workspace_id' => 'ws_river_team', 'platform' => 'x', 'destination' => 'https://example.org/guide',
            'piece_ref' => 'post:'.$post->id.':x', 'placement' => 'post', 'code' => 'river-our-spring-guide-x-post',
        ])
        ->and($call['body'])->not->toHaveKeys(['campaign_id', 'channel_id', 'append_params']);

    $link = TrackedLink::query()->sole();
    expect($link->workspace_id)->toBe($this->teamWs->id)
        ->and($link->short_url)->toBe('https://go.usetantra.com/l/river-our-spring-guide-x-post')
        ->and($link->shortened)->toBeTrue()
        ->and($link->crm_link_id)->toBeNull()->and($link->crm_asset_id)->toBeNull()->and($link->crm_channel_id)->toBeNull();

    // Saving again makes nothing new.
    $post->update(['content' => $post->content.' ']);
    expect(collect($this->calls))->toHaveCount(1);
});

it('keeps Tantra\'s own workspaces on Tantra\'s own campaign, as before', function () {
    $post = teamPost($this->tantraWs, $this->tantraOwner, 'Read this: https://example.org/notes');

    expect($post->content)->toBe('Read this: https://go.usetantra.com/l/sam-read-x-post');
    $paths = collect($this->calls)->pluck('path');
    expect($paths)->not->toContain('/intake/content/links')->and($paths)->toContain('/api/trk/links');
    $made = collect($this->calls)->firstWhere('path', '/api/trk/links');
    expect($made['auth'])->toBe('Bearer trk-key')->and($made['body']['campaign_id'])->toBe('cmp_f427ef8e47514047be1d');
    expect(TrackedLink::query()->sole()->crm_link_id)->toBe('lnk_1');
});

it('leaves the link alone in a workspace no Tantra team owns', function () {
    $post = teamPost($this->loneWs, $this->loneOwner, 'Read this: https://example.org/notes');

    expect($post->content)->toBe('Read this: https://example.org/notes')
        ->and($this->calls)->toBe([])
        ->and(TrackedLink::query()->count())->toBe(0);
});

it('leaves the link as it is when the team has tracking off, and tries the next code when one is taken', function () {
    $this->teamAnswer = fn () => Http::response(['error' => 'off', 'code' => 'tracking_off'], 409);
    $post = teamPost($this->teamWs, $this->teamOwner, 'Read this: https://example.org/notes');
    expect($post->content)->toBe('Read this: https://example.org/notes')->and(TrackedLink::query()->count())->toBe(0);

    $this->teamAnswer = fn (Request $r) => $r['code'] === 'river-read-x-post'
        ? Http::response(['error' => 'taken', 'code' => 'code_taken'], 409)
        : Http::response(['short_url' => 'https://go.usetantra.com/l/'.$r['code'], 'long_url' => null, 'code' => $r['code'], 'created' => true]);
    $other = teamPost($this->teamWs, $this->teamOwner, 'Read this: https://example.org/more');
    expect($other->content)->toBe('Read this: https://go.usetantra.com/l/river-read-x-post-2');
});

it('uses the full link Tantra made for a LinkedIn preview card, and knows it as tracked next time', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->teamWs->id, 'user_id' => $this->teamOwner->id, 'status' => 'draft', 'platform' => Platform::LinkedIn,
        'content' => "New season.\n\nSee it: https://jointantra.com/season",
    ])->refresh();

    expect($post->content)->toBe("New season.\n\nSee it: https://jointantra.com/season?utm_source=linkedin&utm_medium=organic_social&utm_campaign=content-posts&tlid=t1");
    $link = TrackedLink::query()->sole();
    expect($link->shortened)->toBeFalse()->and($link->url())->toBe($link->long_url);

    $before = count($this->calls);
    $post->update(['content' => $post->content.' ']);
    expect(count($this->calls))->toBe($before)
        ->and(PieceLinks::hasUntracked((string) $link->long_url))->toBeFalse();
});
