<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\MediaAltTextGenerator;
use App\Models\User;
use App\Tantra\Ai\AiAlerts;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Ai\AiFailure;
use App\Tantra\Ai\AiUnavailableException;
use App\Tantra\Ai\CliTextGateway;
use App\Tantra\Ai\TantraAi;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
    $this->member = User::factory()->create(['account_id' => $this->owner->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->member->id, membershipPivot('member'));
});

function openAiCompatibleAnswer(string $content): array
{
    return [
        'id' => 'cmpl-1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'm',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];
}

it('lists every provider and shows saved keys only by their last four', function () {
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_secretvalue1234', 'secret_last_four' => '1234', 'is_default' => true]);

    $this->actingAs($this->owner)->get(route('tantra.connectors.index'))
        ->assertOk()
        ->assertDontSee('gsk_secretvalue1234')
        ->assertInertia(fn (Assert $page) => $page->component('tantra/connectors/Index')
            ->where('canManage', true)
            ->where('providers', fn ($providers) => collect($providers)->pluck('key')->contains('omniroute')
                && collect($providers)->pluck('key')->contains('claude_cli')
                && collect($providers)->pluck('key')->contains('codex_cli')
                && collect($providers)->pluck('key')->contains('tantra_api')
                && collect($providers)->firstWhere('key', 'groq')['connector']['secret_last_four'] === '1234'));
});

it('saves a key encrypted, makes the first AI connector the default, and lets only the owner change it', function () {
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.update', 'groq'), ['secret' => 'gsk_abcd9876', 'selected_model' => 'llama-3.3-70b-versatile'])
        ->assertOk()->assertJsonPath('connector.secret_last_four', '9876')->assertJsonPath('connector.is_default', true);

    $raw = DB::table('tantra_ai_connectors')->where('provider', 'groq')->value('secret');
    expect($raw)->not->toContain('gsk_abcd9876')
        ->and(AiConnector::query()->firstWhere('provider', 'groq')->secret)->toBe('gsk_abcd9876');

    $this->actingAs($this->member)->putJson(route('tantra.connectors.update', 'groq'), ['secret' => 'x'])->assertForbidden();
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.update', 'nope'), ['secret' => 'x'])->assertNotFound();
});

it('runs Generate ideas on the default connector', function () {
    Http::fake(['api.groq.com/*' => Http::response(openAiCompatibleAnswer('{"title":"Behind the funnel","body":"Show the six stages."}'))]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k', 'is_default' => true]);

    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'Tantra', 'audience' => 'Founders'])
        ->assertOk()->assertJsonPath('title', 'Behind the funnel');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.groq.com') && $r->hasHeader('Authorization', 'Bearer gsk_k'));
    expect(AiConnector::query()->firstWhere('provider', 'groq')->last_verified_at)->not->toBeNull();
});

it('falls back to the next connector and names the one that ran out of credits', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Insufficient credits. Add more using https://openrouter.ai/credits', 'code' => 402]], 402),
        'api.groq.com/*' => Http::response(openAiCompatibleAnswer('{"title":"Plan B","body":"It still works."}')),
    ]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'openrouter', 'secret' => 'sk-or', 'is_default' => true]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k']);

    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'Tantra', 'audience' => 'Founders'])
        ->assertOk()->assertJsonPath('title', 'Plan B');

    $openrouter = AiConnector::query()->firstWhere('provider', 'openrouter');
    expect($openrouter->last_failed_at)->not->toBeNull()->and($openrouter->last_error)->toStartWith(AiFailure::NO_CREDITS.'|');

    AiAlerts::forget($this->owner->account_id);
    expect(AiAlerts::forAccount($this->owner->account_id))->toHaveCount(1)
        ->and(AiAlerts::forAccount($this->owner->account_id)[0])->toMatchArray(['provider' => 'openrouter', 'reason' => AiFailure::NO_CREDITS, 'level' => 'error']);
});

it('tells the person which provider failed and why when every connector fails', function () {
    Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached for model']], 429)]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k', 'is_default' => true]);

    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'Tantra', 'audience' => 'Founders'])
        ->assertStatus(502)
        ->assertJsonPath('ai_failures.0.provider', 'groq')
        ->assertJsonPath('ai_failures.0.reason', AiFailure::RATE_LIMITED)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Groq hit its rate limit') && str_contains($m, 'Connectors'));
});

it('classifies the common provider failures', function (int $status, string $body, string $reason) {
    $response = new Illuminate\Http\Client\Response(new Response($status, [], $body));
    $failure = AiFailure::from('gemini', new RequestException($response));
    expect($failure->reason)->toBe($reason)->and($failure->message())->toContain('Google Gemini');
})->with([
    'credits' => [402, '{"error":"payment required"}', AiFailure::NO_CREDITS],
    'quota' => [400, '{"error":{"message":"You exceeded your current quota: requests per day"}}', AiFailure::NO_CREDITS],
    'rate' => [429, '{"error":"slow down"}', AiFailure::RATE_LIMITED],
    'rate per minute' => [429, '{"error":{"message":"Rate limit reached for model on tokens per minute (TPM)"}}', AiFailure::RATE_LIMITED],
    'daily allowance' => [429, '{"error":{"message":"Rate limit reached for model on tokens per day (TPD): Limit 100000"}}', AiFailure::NO_CREDITS],
    'key' => [401, '{"error":"invalid api key"}', AiFailure::BAD_KEY],
    'model' => [404, '{"error":"model not found"}', AiFailure::BAD_MODEL],
    'overloaded' => [529, '{"error":"overloaded"}', AiFailure::OVERLOADED],
]);

it('says no AI is connected when there is no connector and no server key', function () {
    config(['ai.default' => 'openai', 'ai.providers.openai.key' => null]);
    Http::fake(['*' => Http::response(['error' => 'no key'], 401)]);

    expect(fn () => TantraAi::prompt(new IdeaGenerator($this->owner->locale, 'b', 'a'), 'x', $this->owner->account_id))
        ->toThrow(AiUnavailableException::class, 'No AI provider is connected');
});

it('tests a connection before saving and records the result on a saved one', function () {
    Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::response(openAiCompatibleAnswer('{"status":"OK"}')), 'api.groq.com/openai/v1/models' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]])]);

    $this->actingAs($this->owner)->postJson(route('tantra.connectors.test', 'groq'), ['secret' => 'gsk_typed'])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('models_available', 1);
    expect(AiConnector::query()->count())->toBe(0);
});

it('checks the Tantra API key against the campaigns endpoint', function () {
    Http::fake(['api.usetantra.com/api/v1/campaigns*' => Http::response(['data' => []])]);

    $this->actingAs($this->owner)->postJson(route('tantra.connectors.test', 'tantra_api'), ['secret' => 'tk_live_abc'])
        ->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'tk_live_abc'));
});

it('builds a CLI prompt that asks for the schema and reads JSON back', function () {
    expect(CliTextGateway::extractJson("Here you go:\n```json\n{\"title\":\"A\",\"body\":\"B\"}\n```"))->toBe(['title' => 'A', 'body' => 'B'])
        ->and(CliTextGateway::extractJson('no json'))->toBeNull();
});

it('promotes another AI connector when the default is removed', function () {
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'a', 'is_default' => true]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'gemini', 'secret' => 'b', 'last_verified_at' => now()]);

    $this->actingAs($this->owner)->deleteJson(route('tantra.connectors.destroy', 'groq'))->assertOk();
    expect(AiConnector::query()->firstWhere('provider', 'gemini')->is_default)->toBeTrue();
});

it('refuses endpoints in a private network and never sends a saved key to a new endpoint', function () {
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.update', 'custom'), ['secret' => 'k', 'base_url' => 'http://169.254.169.254/latest', 'selected_model' => 'm'])
        ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'private network'));
    $this->actingAs($this->owner)->putJson(route('tantra.connectors.update', 'custom'), ['secret' => 'k', 'base_url' => 'http://127.0.0.1:6379', 'selected_model' => 'm'])
        ->assertUnprocessable();

    Http::fake();
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'openai', 'secret' => 'sk-saved']);
    $this->actingAs($this->owner)->postJson(route('tantra.connectors.models', 'openai'), ['base_url' => 'https://attacker.example/v1'])
        ->assertUnprocessable();
    Http::assertNothingSent();
});

it('moves on to the next connector when one has no model or throws an unexpected error', function () {
    Http::fake(['api.groq.com/*' => Http::response(openAiCompatibleAnswer('{"title":"Still here","body":"b"}'))]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'custom', 'secret' => 'k', 'base_url' => 'https://example.com/v1', 'is_default' => true]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k']);

    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'Tantra', 'audience' => 'Founders'])
        ->assertOk()->assertJsonPath('title', 'Still here');
    expect(AiConnector::query()->firstWhere('provider', 'custom')->last_failed_at)->not->toBeNull();
});

it('reports an exhausted quota sent as a 429 as out of credits', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'You exceeded your current quota, please check your plan and billing details.', 'type' => 'insufficient_quota']], 429)]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'openai', 'secret' => 'sk', 'is_default' => true]);

    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'Tantra', 'audience' => 'Founders'])
        ->assertStatus(502)->assertJsonPath('ai_failures.0.reason', AiFailure::NO_CREDITS)
        ->assertJsonMissingPath('ai_failures.0.detail');
});

it('keeps the provider\'s raw words off the banner and away from members', function () {
    Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'upstream said: internal-host db01.corp']], 500)]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk', 'is_default' => true]);
    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'b', 'audience' => 'a'])->assertStatus(502);

    AiAlerts::forget($this->owner->account_id);
    expect(json_encode(AiAlerts::forAccount($this->owner->account_id)))->not->toContain('db01.corp');

    $this->actingAs($this->member)->get(route('tantra.connectors.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($p) => ! str_contains(json_encode($p), 'db01.corp')));
    $this->actingAs($this->owner)->get(route('tantra.connectors.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($p) => str_contains(json_encode($p), 'db01.corp')));
});

it('says when no connected provider can read images', function () {
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk', 'is_default' => true]);

    expect(fn () => TantraAi::prompt(new MediaAltTextGenerator($this->owner->locale), 'x', $this->owner->account_id, ['image']))
        ->toThrow(AiUnavailableException::class, 'can read images');
});

it('runs Claude Code with only its token in the environment', function () {
    $dir = sys_get_temp_dir().'/fake-claude-'.uniqid();
    mkdir($dir);
    $bin = $dir.'/claude';
    file_put_contents($bin, "#!/bin/sh\ncat > /dev/null\nleak=\$(env | grep -c -E '^(APP_KEY|DB_PASSWORD)=')\nhas=\$( [ -n \"\$CLAUDE_CODE_OAUTH_TOKEN\" ] && echo yes || echo no)\nprintf '{\"type\":\"result\",\"subtype\":\"success\",\"is_error\":false,\"result\":\"{\\\\\"title\\\\\":\\\\\"leak=%s token=%s\\\\\",\\\\\"body\\\\\":\\\\\"b\\\\\"}\"}' \"\$leak\" \"\$has\"\n");
    chmod($bin, 0755);
    config(['tantra.cli.claude' => $bin]);
    putenv('APP_KEY=base64:should-not-leak');
    putenv('DB_PASSWORD=should-not-leak');

    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'claude_cli', 'secret' => 'sk-ant-oat01-test', 'is_default' => true]);

    $this->actingAs($this->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'Tantra', 'audience' => 'Founders'])
        ->assertOk()->assertJsonPath('title', 'leak=0 token=yes');

    putenv('APP_KEY');
    putenv('DB_PASSWORD');
    @unlink($bin);
    @rmdir($dir);
});

it('asks for the key again before saving a new endpoint for a saved key', function () {
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'custom', 'secret' => 'k', 'base_url' => 'https://one.example/v1', 'selected_model' => 'm']);

    $this->actingAs($this->owner)->putJson(route('tantra.connectors.update', 'custom'), ['base_url' => 'https://two.example/v1'])
        ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'Type the key again'));
    expect(AiConnector::query()->firstWhere('provider', 'custom')->base_url)->toBe('https://one.example/v1');
});

it('does not call a Gemini per-minute quota "out of credits"', function () {
    $response = new Illuminate\Http\Client\Response(new Response(429, [], '{"error":{"message":"You exceeded your current quota. Quota exceeded for metric: generate_content_free_tier_requests, limit: 10 per minute"}}'));
    expect(AiFailure::from('gemini', new RequestException($response))->reason)->toBe(AiFailure::RATE_LIMITED);
});
