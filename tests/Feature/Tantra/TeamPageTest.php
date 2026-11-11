<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);
    Http::fake(['unipile.test/v2/accounts' => Http::response(['data' => [
        ['id' => 'acc_nelbin', 'type' => 'LINKEDIN', 'name' => 'Nelbin Joseph', 'status' => 'OK'],
    ]])]);

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->nelbin = User::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Nelbin Joseph', 'current_workspace_id' => $result['workspace']->id]);
    $result['workspace']->members()->attach($this->nelbin->id, membershipPivot('member'));
});

it('suggests a Unipile account by name and lets the Account owner confirm it', function () {
    $this->actingAs($this->owner)->get(route('tantra.team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/team/Index')
            ->where('isOwner', true)
            ->where('unipileAccounts.0.suggested_for', 'Nelbin Joseph'));

    $this->actingAs($this->owner)->postJson(route('tantra.team.match'), ['user_id' => $this->nelbin->id, 'unipile_account_id' => 'acc_nelbin'])->assertOk();

    expect(MemberProfile::query()->find($this->nelbin->id))
        ->unipile_account_id->toBe('acc_nelbin')
        ->unipile_confirmed_by->toBe($this->owner->id);

    $this->actingAs($this->nelbin)->postJson(route('tantra.team.match'), ['user_id' => $this->nelbin->id, 'unipile_account_id' => null])->assertForbidden();
});

it('records consent only for the person themself', function () {
    $this->actingAs($this->nelbin)->postJson(route('tantra.team.consent'), ['on' => true])->assertOk();
    expect(MemberProfile::query()->find($this->nelbin->id)->boost_consent_at)->not->toBeNull();

    $this->actingAs($this->nelbin)->get(route('tantra.team.index'))
        ->assertInertia(fn (Assert $page) => $page->where('isOwner', false)->has('members', 1)->where('members.0.consent', true));

    $this->actingAs($this->nelbin)->postJson(route('tantra.team.consent'), ['on' => false])->assertOk();
    expect(MemberProfile::query()->find($this->nelbin->id)->boost_consent_at)->toBeNull();
});

it('shows each person their setup checklist', function () {
    $this->actingAs($this->nelbin)->postJson(route('tantra.team.expertise'), ['expertise' => 'Outbound for SaaS founders.'])->assertOk();

    $this->actingAs($this->nelbin)->get(route('tantra.team.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members.0.checklist.joined', true)
            ->where('members.0.checklist.expertise', true)
            ->where('members.0.checklist.linkedin', false)
            ->where('members.0.checklist.unipile', false)
            ->where('members.0.checklist.api_key', false));
});
