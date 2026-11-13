<?php

declare(strict_types=1);

use App\Models\User;
use App\Tantra\Repurpose\Cadence;
use App\Tantra\Repurpose\RepurposePreset;
use App\Tantra\Repurpose\RepurposeRule;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->token = $result['plain_token'];
    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id, 'current_workspace_id' => $result['workspace']->id]);
    $result['workspace']->members()->attach($this->ben->id, membershipPivot('member'));
});

it('starts from the default cadence and lets the Account owner change it', function () {
    $this->actingAs($this->adi)->get('/tantra/repurposing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tantra/repurposing/Index')
            ->where('cadence.sequence.1.network', 'facebook')->where('canEdit', true));

    $this->actingAs($this->adi)->putJson('/tantra/repurposing/cadence', ['enabled' => true, 'sequence' => [
        ['network' => 'linkedin', 'week' => 0, 'enabled' => true],
        ['network' => 'x', 'week' => 1, 'enabled' => true],
        ['network' => 'facebook', 'week' => 2, 'enabled' => false],
    ]])->assertOk();

    expect(Cadence::for($this->adi->account_id)->sequence[1]['network'])->toBe('x');
    $this->actingAs($this->ben)->putJson('/tantra/repurposing/cadence', ['enabled' => false, 'sequence' => [['network' => 'x', 'week' => 0, 'enabled' => true]]])->assertForbidden();
});

it('keeps an Account\'s own rules on top of the preset', function () {
    $this->withToken($this->token)->postJson('/api/tantra/repurposing/presets', ['presets' => [
        ['network' => 'facebook', 'title' => 'LinkedIn to Facebook', 'rules' => 'Keep it under 500 characters.'],
    ]])->assertOk();
    $this->withToken($this->token)->postJson('/api/tantra/repurposing/presets', ['presets' => [
        ['network' => 'facebook', 'title' => 'LinkedIn to Facebook', 'rules' => 'Keep it under 400 characters.'],
    ]])->assertOk();
    expect(RepurposePreset::query()->sole()->version)->toBe(2);

    $this->actingAs($this->adi)->putJson('/tantra/repurposing/rules/facebook', ['rules' => 'Always end with a question.'])->assertOk();
    $this->withToken($this->token)->getJson('/api/tantra/repurposing')->assertOk()
        ->assertJsonPath('data.presets.0.custom', 'Always end with a question.');

    $this->actingAs($this->adi)->putJson('/tantra/repurposing/rules/facebook', ['rules' => ''])->assertOk();
    expect(RepurposeRule::query()->count())->toBe(0);
    $this->actingAs($this->ben)->postJson('/api/tantra/repurposing/presets', ['presets' => []])->assertStatus(401);
});
