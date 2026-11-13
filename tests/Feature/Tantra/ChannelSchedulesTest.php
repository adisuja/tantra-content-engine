<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Channels\ChannelSchedules;
use App\Tantra\Mcp\Tools\TantraGetChannelScheduleTool;
use App\Tantra\Mcp\Tools\TantraUpdateChannelScheduleTool;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/** tantra: each teammate's posting days and times per channel (PRD 25.6). */
beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ownerWorkspace = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];

    $this->nelbin = User::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Nelbin Joseph']);
    $this->nelbinWorkspace = tantraTeammateWorkspace($this->owner, $this->nelbin);
    $this->ben = User::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Ben Hall']);
    $this->benWorkspace = tantraTeammateWorkspace($this->owner, $this->ben);

    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '10:30');
    }

    // A personal profile through Unipile and an X account: one grid each.
    $this->profile = SocialAccount::factory()->create(['workspace_id' => $this->nelbinWorkspace->id, 'platform' => Platform::UnipileLinkedIn, 'timezone' => 'Asia/Kolkata', 'posting_schedule' => $weekdays]);
    $this->x = SocialAccount::factory()->create(['workspace_id' => $this->nelbinWorkspace->id, 'platform' => Platform::X, 'timezone' => 'Asia/Kolkata', 'posting_goal' => 5, 'posting_schedule' => $weekdays]);
});

function tantraTeammateWorkspace(User $owner, User $member): Workspace
{
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id, 'name' => $member->name]);
    $workspace->members()->attach($member->id, membershipPivot('admin'));
    $member->update(['current_workspace_id' => $workspace->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $workspace->id, 'review_owner_user_id' => $member->id]);

    return $workspace;
}

/** @return array<string, mixed> */
function tantraScheduleBody(string $timezone = 'Asia/Kolkata', string $time = '08:00'): array
{
    return [
        'timezone' => $timezone,
        'posting_goal' => 5,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, $time)->withTime(3, $time)->toArray(),
    ];
}

it('lists every channel a person reviews on the Team page, one grid each', function () {
    $this->actingAs($this->nelbin)->get(route('tantra.team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/team/Index')
            ->has('members', 1)
            ->has('members.0.channels', 2)
            ->where('members.0.channels.0.schedule.timezone', 'Asia/Kolkata')
            ->where('members.0.channels.0.confirmed', false)
            ->where('members.0.checklist.schedule', false)
            ->has('timezones'));

    $this->actingAs($this->owner)->get(route('tantra.team.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members): bool => count(collect($members)->firstWhere('id', $this->nelbin->id)['channels']) === 2
                && count(collect($members)->firstWhere('id', $this->ben->id)['channels']) === 0));
});

it('lets the review owner set the times of their own channel and reflows its queue', function () {
    $this->actingAs($this->nelbin)
        ->putJson(route('tantra.team.channels.schedule', $this->profile), tantraScheduleBody('America/New_York'))
        ->assertOk()
        ->assertJsonPath('timezone', 'America/New_York')
        ->assertJsonPath('posting_schedule.1.times', ['08:00']);

    $profile = $this->profile->refresh();
    expect($profile->posting_schedule->slotCount())->toBe(2)
        ->and($profile->posting_goal)->toBe(5)
        ->and(ChannelSchedules::confirmed($profile))->toBeTrue();
});

it('keeps the goal and times when only the timezone is sent', function () {
    $this->actingAs($this->nelbin)
        ->putJson(route('tantra.team.channels.schedule', $this->x), ['timezone' => 'Europe/London'])
        ->assertOk()
        ->assertJsonPath('timezone', 'Europe/London')
        ->assertJsonPath('posting_goal', 5);

    expect($this->x->refresh()->posting_schedule->slotCount())->toBe(5);
});

it('validates with the upstream schedule rules', function () {
    $body = tantraScheduleBody();
    $body['posting_schedule'][1]['times'] = ['08:00', '08:00'];

    $this->actingAs($this->nelbin)->putJson(route('tantra.team.channels.schedule', $this->profile), $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('posting_schedule.1.times.0');

    $this->actingAs($this->nelbin)->putJson(route('tantra.team.channels.schedule', $this->profile), ['timezone' => 'Mars/Base'])
        ->assertJsonValidationErrors('timezone');
});

it('refuses another teammate', function () {
    $this->actingAs($this->ben)
        ->putJson(route('tantra.team.channels.schedule', $this->profile), tantraScheduleBody())
        ->assertForbidden();

    expect($this->profile->refresh()->posting_schedule->slotCount())->toBe(5);

    $outsider = createApiTestToken()['user'];
    $this->actingAs($outsider)
        ->putJson(route('tantra.team.channels.schedule', $this->profile), tantraScheduleBody())
        ->assertNotFound();
});

it('lets the Account owner set anyone\'s times', function () {
    $this->actingAs($this->owner)
        ->putJson(route('tantra.team.channels.schedule', $this->x), tantraScheduleBody('UTC', '09:15'))
        ->assertOk()
        ->assertJsonPath('posting_schedule.3.times', ['09:15']);
});

it('ticks posting times on the checklist once every channel has checked times', function () {
    foreach ([$this->profile, $this->x] as $channel) {
        $this->actingAs($this->nelbin)->putJson(route('tantra.team.channels.schedule', $channel), tantraScheduleBody())->assertOk();
    }

    $this->actingAs($this->nelbin)->get(route('tantra.team.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members.0.checklist.schedule', true)
            ->where('members.0.channels.1.confirmed', true));
});

it('reads and sets any channel\'s times through the API with the owner token', function () {
    $this->withHeaders($this->headers)
        ->getJson(route('tantra.api.channels.schedule.show', $this->profile))
        ->assertOk()
        ->assertJsonPath('id', $this->profile->id)
        ->assertJsonPath('timezone', 'Asia/Kolkata');

    $this->withHeaders($this->headers)
        ->putJson(route('tantra.api.channels.schedule.update', $this->profile), tantraScheduleBody('Asia/Dubai'))
        ->assertOk()
        ->assertJsonPath('timezone', 'Asia/Dubai');

    expect($this->profile->refresh()->timezone)->toBe('Asia/Dubai');

    $foreign = SocialAccount::factory()->create(['workspace_id' => createApiTestToken()['workspace']->id]);
    $this->withHeaders($this->headers)->getJson(route('tantra.api.channels.schedule.show', $foreign))->assertNotFound();
});

it('refuses the API to a teammate\'s own key', function () {
    $token = passportToken($this->nelbin, $this->nelbinWorkspace);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson(route('tantra.api.channels.schedule.show', $this->profile))
        ->assertForbidden();
});

it('reads and sets times through MCP with the same rules', function () {
    TryPostServer::actingAs($this->nelbin)->tool(TantraGetChannelScheduleTool::class, ['channel_id' => $this->x->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('posting_goal', 5)->etc());

    TryPostServer::actingAs($this->nelbin)->tool(TantraUpdateChannelScheduleTool::class, ['channel_id' => $this->x->id, ...tantraScheduleBody('Europe/Berlin')])
        ->assertOk();
    expect($this->x->refresh()->timezone)->toBe('Europe/Berlin');

    TryPostServer::actingAs($this->ben)->tool(TantraUpdateChannelScheduleTool::class, ['channel_id' => $this->x->id, ...tantraScheduleBody()])
        ->assertHasErrors(['Channel not found.']);
});

it('flashes every channel one connection created, for the posting times step', function () {
    $workspace = $this->ownerWorkspace;
    startSocialConnect($workspace, Platform::LinkedIn);

    $person = Mockery::mock(SocialiteUser::class);
    $person->shouldReceive('getId')->andReturn('person-123');
    $person->shouldReceive('getName')->andReturn('Adi Suja');
    $person->shouldReceive('getAvatar')->andReturn(null);
    $person->token = 'test-access-token';
    $person->refreshToken = 'test-refresh-token';
    $person->expiresIn = 5184000;
    $person->approvedScopes = ['openid', 'profile', 'email', 'w_member_social'];
    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn(Mockery::mock(['user' => $person]));
    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/me*' => Http::response(['id' => 'person-123', 'vanityName' => 'adi'], 200),
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response(['elements' => [
            ['organization~' => ['id' => 111, 'localizedName' => 'Tantra']],
            ['organization~' => ['id' => 222, 'localizedName' => 'Growth Club']],
        ]], 200),
    ]);

    $this->actingAs($this->owner)->get(route('app.social.linkedin.callback'))->assertRedirect();

    $response = finishSocialConnect(Platform::LinkedIn)->assertRedirect();

    $created = $workspace->socialAccounts()->pluck('id')->sort()->values()->all();
    expect($created)->toHaveCount(3);
    $response->assertInertiaFlash('connectedChannel.created', true);
    expect(collect(session()->get(SessionKey::FLASH_DATA)['connectedChannels'] ?? [])->sort()->values()->all())->toBe($created);
});
