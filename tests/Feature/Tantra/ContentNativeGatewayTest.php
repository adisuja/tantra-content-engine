<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Create\CreateFromFormat;
use App\Tantra\Create\CreatePiece;
use App\Tantra\Create\FormatPostWriter;
use App\Tantra\Gateway\GatewaySignature;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Templates\ContentTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

// tantra: AF-09, Tantra's native Content > Create and Review over the signed gateway (GatewayContentController).

const NATIVE_GW_SECRET = 'native-gateway-secret-0123456789abcdef-xyz';
const NATIVE_GW_CLERK = 'user_NativeGateway01234567';

/** @param array<string, mixed> $body */
function nativeGatewayCall(object $test, string $method, string $path, array $body = [], string $scopes = 'content:read,content:write,content:publish'): TestResponse
{
    $raw = $body === [] ? '' : json_encode($body);
    $sig = GatewaySignature::sign(NATIVE_GW_SECRET, 'k1', time(), 'ws_crm_team', NATIVE_GW_CLERK, $scopes, $method, $path, $raw);

    return $test->call($method, $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TANTRA_SIGNATURE' => $sig,
        'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team',
        'HTTP_X_TANTRA_MEMBER' => NATIVE_GW_CLERK,
        'HTTP_X_TANTRA_SCOPES' => $scopes,
    ], $raw);
}

function nativeTemplate(string $key = 'LI7', array $overrides = []): ContentTemplate
{
    return ContentTemplate::query()->forceCreate([
        'key' => $key, 'title' => 'The five slide carousel', 'platform' => 'linkedin', 'format' => 'carousel',
        'description' => 'Five slides that turn one lesson into steps.', 'spec' => 'Slide one is the hook.',
        'copy_template' => 'Hook. Five steps. Ask.', 'status' => 'live', 'origin' => 'seed', 'account_id' => null, ...$overrides,
    ]);
}

beforeEach(function () {
    Cache::flush();
    config(['tantra_gateway.enabled' => true, 'tantra_gateway.secrets' => ['k1' => NATIVE_GW_SECRET]]);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->user->forceFill(['clerk_user_id' => NATIVE_GW_CLERK])->save();
    WorkspaceSettings::query()->create(['workspace_id' => $this->workspace->id, 'brand_profile_key' => 'native', 'review_owner_user_id' => $this->user->id]);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
});

describe('format cards', function () {
    it('lists the live templates as cards named by key, the ones already used first', function () {
        nativeTemplate('LI1', ['title' => 'Alpha']);
        nativeTemplate('LI2', ['title' => 'Beta']);
        nativeTemplate('LI3', ['title' => 'Draft only', 'status' => 'draft']);
        ImportBatch::execute($this->user, ['engine_batch_id' => 'b1', 'posts' => [['engine_post_id' => 'P1', 'profile' => 'native', 'platform' => 'linkedin', 'text' => 'Used.', 'sample' => ['id' => 'LI2']]]]);

        $cards = nativeGatewayCall($this, 'GET', '/api/gateway/tantra/formats')->assertOk()->json('data');

        expect(collect($cards)->pluck('key')->all())->toBe(['LI2', 'LI1'])
            ->and($cards[0]['used'])->toBe(1)
            ->and($cards[0])->not->toHaveKey('id');
    });

    it('needs the read scope', function () {
        nativeGatewayCall($this, 'GET', '/api/gateway/tantra/formats', scopes: 'content:write')->assertForbidden();
    });
});

describe('create', function () {
    it('starts a piece on the queue and answers at once', function () {
        Queue::fake();
        nativeTemplate();

        $r = nativeGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Why one call can fill a week of posts'])
            ->assertStatus(202)->assertJsonPath('data.status', 'creating')->assertJsonPath('data.format', 'LI7')->assertJsonPath('data.platform', 'linkedin');

        Queue::assertPushed(CreatePiece::class);
        expect($r->json('data'))->not->toHaveKeys(['user_id', 'workspace_id', 'idea_full']);
        nativeGatewayCall($this, 'GET', '/api/gateway/tantra/create/'.$r->json('data.ref'))->assertOk()->assertJsonPath('data.status', 'creating');
        nativeGatewayCall($this, 'GET', '/api/gateway/tantra/create')->assertOk()->assertJsonCount(1, 'data');
    });

    it('writes the piece and pushes it to Review, with no en or em dash', function () {
        Queue::fake();
        nativeTemplate();
        FormatPostWriter::fake([['title' => 'One call, a week of posts', 'text' => "One call \u{2014} five posts.\nDays 1\u{2013}5.", 'first_comment' => '']]);

        $ref = nativeGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'One call fills a week'])->assertStatus(202)->json('data.ref');
        CreateFromFormat::run($ref);

        $piece = nativeGatewayCall($this, 'GET', "/api/gateway/tantra/create/{$ref}")->assertOk()->json('data');
        $item = ReviewItem::query()->findOrFail($piece['review_ref']);

        expect($piece['status'])->toBe('ready')
            ->and($item->review_status)->toBe(ReviewStatus::InReview)
            ->and($item->payload['text'])->toBe("One call, five posts.\nDays 1 to 5.")
            ->and($item->payload['sample']['id'])->toBe('LI7');
    });

    it('says in words when the format or the idea is not right', function () {
        nativeGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'ZZ9', 'idea' => 'Something'])->assertStatus(422)->assertJsonValidationErrors('format');
        nativeTemplate();
        nativeGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'x'])->assertStatus(422)->assertJsonValidationErrors('idea');
    });

    it('needs the write scope', function () {
        nativeTemplate();
        nativeGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'An idea'], scopes: 'content:read')->assertForbidden();
    });
});

describe('review', function () {
    beforeEach(function () {
        ImportBatch::execute($this->user, ['engine_batch_id' => 'b2', 'posts' => [['engine_post_id' => 'R1', 'profile' => 'native', 'platform' => 'linkedin', 'text' => 'Waiting for you.']]]);
        $this->item = ReviewItem::query()->where('engine_post_id', 'R1')->sole();
    });

    it('lists what waits with what the person may do', function () {
        $data = nativeGatewayCall($this, 'GET', '/api/gateway/tantra/review')->assertOk()->json('data');

        expect($data['counts']['waiting'])->toBe(1)
            ->and($data['items'][0]['ref'])->toBe($this->item->id)
            ->and($data['items'][0]['text'])->toBe('Waiting for you.')
            ->and($data['items'][0]['can_approve'])->toBeTrue()
            ->and($data['items'][0]['can_edit'])->toBeTrue();
    });

    it('edits the text of a waiting post and refuses dashes', function () {
        nativeGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$this->item->id}", ['text' => 'New words.'])->assertOk()->assertJsonPath('data.text', 'New words.');
        nativeGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$this->item->id}", ['text' => "A \u{2014} B"])->assertStatus(422);

        expect($this->item->refresh()->payload['text'])->toBe('New words.');
    });

    it('rejects a post, and approving or rejecting needs the publish scope', function () {
        nativeGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/approve", scopes: 'content:read,content:write')->assertForbidden();
        nativeGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/reject", scopes: 'content:read,content:write')->assertForbidden();

        nativeGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$this->item->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        expect($this->item->refresh()->review_status)->toBe(ReviewStatus::Rejected);
    });

    it('lists a post someone else approves with who approves it; on the gateway the CRM applies the approver rule (M2)', function () {
        $mate = User::factory()->create(['account_id' => $this->user->account_id, 'name' => 'Sam Reviewer']);
        $team = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $mate->id]);
        $team->members()->attach($mate->id, membershipPivot('admin'));
        WorkspaceSettings::query()->create(['workspace_id' => $team->id, 'brand_profile_key' => 'mate', 'review_owner_user_id' => $mate->id]);
        ImportBatch::execute($this->user, ['engine_batch_id' => 'b3', 'posts' => [['engine_post_id' => 'M1', 'profile' => 'mate', 'platform' => 'linkedin', 'text' => 'Waiting for someone else.']]]);
        $other = ReviewItem::query()->where('engine_post_id', 'M1')->sole();

        $data = nativeGatewayCall($this, 'GET', '/api/gateway/tantra/review')->assertOk()->json('data');
        $rows = collect($data['items'])->keyBy('ref');

        expect($data['counts']['waiting'])->toBe(2)
            ->and($data['counts']['waiting_others'])->toBe(1)
            ->and($rows[$other->id]['approver'])->toBe('Sam Reviewer')
            ->and($rows[$other->id]['waiting_on_other'])->toBeTrue()
            ->and($rows[$other->id]['can_approve'])->toBeTrue()
            ->and($rows[$other->id]['can_edit'])->toBeFalse()
            ->and($rows[$other->id]['can_reject'])->toBeTrue()
            ->and($rows[$this->item->id]['waiting_on_other'])->toBeFalse()
            ->and($rows[$this->item->id]['approver'])->toBe($this->user->name);

        // M2: approving is the CRM's call (CX-03); editing the text keeps the review owner rule.
        nativeGatewayCall($this, 'POST', "/api/gateway/tantra/review/{$other->id}/reject")->assertOk();
        nativeGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$other->id}", ['text' => 'Not mine to edit.'])->assertStatus(422);
        expect($other->refresh()->review_status)->toBe(ReviewStatus::Rejected)
            ->and($other->payload['text'])->toBe('Waiting for someone else.');
    });

    it('answers 404 for a post the person cannot see', function () {
        nativeGatewayCall($this, 'POST', '/api/gateway/tantra/review/00000000-0000-4000-8000-000000000000/reject')->assertNotFound();
    });
});
