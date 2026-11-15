<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\PostContentShortener;
use App\Enums\SocialAccount\Platform;
use App\Models\Workspace;
use App\Services\Repurpose\CaptionAdapter;
use App\Tantra\Ai\AiConnector;
use App\Tantra\Ai\AiUsage;
use App\Tantra\Ai\TantraAi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// tantra: A-05b. Every engine AI call is a row for Tantra's AI cost ledger, and tantra:report-ai-usage sends the rows
// to POST /api/ai/usage with the server key. CaptionAdapter runs on the Account's connectors like every other call.

beforeEach(function () {
    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->workspace = $result['workspace'];
});

function groqAnswer(string $content, int $in = 120, int $out = 30): array
{
    return [
        'id' => 'cmpl-1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-oss-120b',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
    ];
}

it('records a connector call with its task, provider, model and tokens', function () {
    Http::fake(['api.groq.com/*' => Http::response(groqAnswer('{"title":"Behind the funnel","body":"Show the six stages."}'))]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k', 'is_default' => true]);

    TantraAi::prompt(new IdeaGenerator($this->owner->locale, 'Tantra', 'Founders'), 'Suggest one content idea.', $this->owner->account_id, task: 'content.idea');

    $row = DB::table('tantra_ai_usages')->sole();
    expect($row->task)->toBe('content.idea')
        ->and($row->account_id)->toBe((string) $this->owner->account_id)
        ->and($row->provider)->toBe('groq')
        ->and($row->model)->not->toBeNull()
        ->and((int) $row->input_tokens)->toBe(120)
        ->and((int) $row->output_tokens)->toBe(30)
        ->and($row->outcome)->toBe('ok')
        ->and($row->reported_at)->toBeNull();
});

it('records a failed connector by its reason, and nothing for a call with no task', function () {
    Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached for model req_abc123']], 429)]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k', 'is_default' => true]);

    expect(fn () => TantraAi::prompt(new IdeaGenerator($this->owner->locale, 'b', 'a'), 'x', $this->owner->account_id, task: 'content.idea'))->toThrow(Exception::class);
    $row = DB::table('tantra_ai_usages')->sole();
    expect($row->outcome)->toBe('error')
        ->and($row->error)->not->toContain('req_abc123');

    expect(fn () => TantraAi::prompt(new IdeaGenerator($this->owner->locale, 'b', 'a'), 'x', $this->owner->account_id))->toThrow(Exception::class);
    expect(DB::table('tantra_ai_usages')->count())->toBe(1);
});

it('shortens a caption on the Account\'s connector, not only the server provider (CaptionAdapter)', function () {
    config()->set('trypost.self_hosted', true);
    Http::fake(['api.groq.com/*' => Http::response(groqAnswer('A tight caption that fits.'))]);
    AiConnector::query()->create(['account_id' => $this->owner->account_id, 'provider' => 'groq', 'secret' => 'gsk_k', 'is_default' => true]);
    $workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);

    $result = app(CaptionAdapter::class)->adapt($workspace, $this->owner, str_repeat('palavra ', 2000), Platform::YouTube);

    expect($result)->toBe('A tight caption that fits.');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.groq.com') && $r->hasHeader('Authorization', 'Bearer gsk_k'));
    expect(DB::table('tantra_ai_usages')->where('task', 'content.shorten')->where('provider', 'groq')->count())->toBe(1);
});

it('still shortens with a test fake when the Account has no connector', function () {
    config()->set('trypost.self_hosted', true);
    PostContentShortener::fake(['A tight caption that fits.']);
    $workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);

    expect(app(CaptionAdapter::class)->adapt($workspace, $this->owner, str_repeat('palavra ', 2000), Platform::YouTube))->toBe('A tight caption that fits.');
    expect(DB::table('tantra_ai_usages')->where('task', 'content.shorten')->count())->toBe(1);
});

it('sends unsent rows to Tantra with the server key, once, and keeps them on a server error', function () {
    config()->set('tantra.crm.url', 'https://crm.example.test');
    config()->set('tantra.crm.api_key', 'ck_test_key');
    AiUsage::record('content.assist', (string) $this->owner->account_id, 'anthropic', 'claude-sonnet-5-5');
    AiUsage::record('content.shorten', (string) $this->owner->account_id, 'groq', 'openai/gpt-oss-120b');
    AiUsage::record('not.a.task', null, 'groq', 'x');
    expect(DB::table('tantra_ai_usages')->count())->toBe(2);
    // Rows made in the same second have no order; pin the first one earlier so the send order is fixed.
    DB::table('tantra_ai_usages')->where('task', 'content.assist')->update(['created_at' => now()->subMinute()]);

    Http::fakeSequence('crm.example.test/*')->push(['recorded' => true], 201)->push(['error' => 'busy'], 503);
    $this->artisan('tantra:report-ai-usage')->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://crm.example.test/api/ai/usage' && $r->hasHeader('Authorization', 'Bearer ck_test_key')
        && $r['task'] === 'content.assist' && $r['model'] === 'claude-sonnet-5-5' && str_starts_with((string) $r['turn_id'], 'engine-'));
    expect(DB::table('tantra_ai_usages')->whereNotNull('reported_at')->count())->toBe(1)
        ->and(DB::table('tantra_ai_usages')->whereNull('reported_at')->value('task'))->toBe('content.shorten');

    // The next run sends what was left; a 4xx closes the row with its reason.
    Http::fake(['crm.example.test/*' => Http::response(['error' => 'task must be one of'], 422)]);
    $this->artisan('tantra:report-ai-usage')->assertSuccessful();
    expect(DB::table('tantra_ai_usages')->whereNull('reported_at')->count())->toBe(0)
        ->and(DB::table('tantra_ai_usages')->where('task', 'content.shorten')->value('report_error'))->toContain('422');
});

it('sends nothing without the server key, or when reporting is off', function () {
    Http::fake();
    config()->set('tantra.crm.api_key', null);
    AiUsage::record('content.assist', null, 'anthropic', 'claude-sonnet-5-5');
    $this->artisan('tantra:report-ai-usage')->assertSuccessful();
    config()->set('tantra.crm.api_key', 'ck_test_key');
    config()->set('tantra.crm.ai_usage', false);
    $this->artisan('tantra:report-ai-usage')->assertSuccessful();
    Http::assertNothingSent();
    expect(DB::table('tantra_ai_usages')->whereNull('reported_at')->count())->toBe(1);
});

it('builds the body Tantra takes, with no price', function () {
    AiUsage::record('content.alt_text', null, 'Gemini', 'gemini-2.5-flash');
    $body = AiUsage::body(DB::table('tantra_ai_usages')->sole());
    expect($body)->toMatchArray(['task' => 'content.alt_text', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'outcome' => 'ok'])
        ->and($body)->not->toHaveKey('cost_usd')
        ->and(strlen($body['turn_id']))->toBeLessThanOrEqual(120);
});
