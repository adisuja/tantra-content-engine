<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\EngagementAsset;
use App\Tantra\Review\Jobs\SyncEngagementAssets;
use App\Tantra\Review\Messages;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// tantra: a batch pushed as the Content Studio writes it keeps its DM fields (DM, connection note, email and
// resource link), shows them on the post, and hands them to the CRM's Engagement asset library.

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    Mail::fake();
    config(['tantra.crm.api_key' => null, 'tantra.crm.url' => 'https://crm.test', 'tantra.crm.engagement_assets' => true]);

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];
    $this->owner->account->update(['owner_id' => $this->owner->id]);
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];
    WorkspaceSettings::query()->create(['workspace_id' => $this->adi->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    SocialAccount::factory()->create(['workspace_id' => $this->adi->id, 'platform' => Platform::LinkedIn]);
});

/** @param array<string, mixed> $overrides */
function studioGiveaway(array $overrides = []): array
{
    return [
        'engine_post_id' => 'AG3',
        'profile' => 'adi',
        'platform' => 'linkedin',
        'date' => '2026-11-24',
        'time' => '08:30',
        'tz' => 'Asia/Kolkata',
        'text' => 'Comment PROMPTS and I will send the pack.',
        'first_comment' => 'Comment PROMPTS below.',
        'resource' => [
            'name' => 'The Prompt Pack',
            'keyword' => 'PROMPTS',
            'what' => '40 prompts for revenue teams.',
            'url' => 'https://content.usetantra.com/r/prompt-pack',
            'files' => [['label' => 'PDF', 'href' => 'https://content.usetantra.com/r/prompt-pack.pdf']],
        ],
        // The Studio's own block (posts.json), as Studio batches carry it.
        'dm' => [
            'message' => "Hi {first_name}, here is the Prompt Pack:\nhttps://content.usetantra.com/r/prompt-pack",
            'connect' => 'Hi {first_name}, thanks for commenting PROMPTS. Accept this and I will send the pack.',
            'email_subject' => 'Your Prompt Pack',
            'email_body' => "Hi {first_name},\n\nYou asked on [POST LINK]. Here it is:\nhttps://content.usetantra.com/r/prompt-pack",
        ],
        ...$overrides,
    ];
}

function pushStudioBatch(object $test, array $posts): TestResponse
{
    return $test->withHeaders($test->headers)->postJson(route('tantra.api.batches.store'), [
        'engine_batch_id' => '7',
        'title' => 'Batch 7',
        'posts' => $posts,
    ]);
}

it('keeps the Studio dm block and the resource link on each imported post', function () {
    pushStudioBatch($this, [studioGiveaway()])->assertOk()->assertJsonPath('data.items.0.outcome', 'created');

    $item = ReviewItem::query()->sole();
    expect(data_get($item->payload, 'dm.connect'))->toBe('Hi {first_name}, thanks for commenting PROMPTS. Accept this and I will send the pack.')
        ->and(data_get($item->payload, 'resource.url'))->toBe('https://content.usetantra.com/r/prompt-pack');

    $messages = Messages::for($item);
    expect($messages['linkedin_dm']['text'])->toBe("Hi {first_name}, here is the Prompt Pack:\nhttps://content.usetantra.com/r/prompt-pack")
        ->and($messages['linkedin_connect']['text'])->toBe('Hi {first_name}, thanks for commenting PROMPTS. Accept this and I will send the pack.')
        ->and($messages['email_subject']['text'])->toBe('Your Prompt Pack')
        ->and($messages['email_body']['text'])->toStartWith("Hi {first_name},\n\nYou asked on [POST LINK].")
        // WhatsApp reuses the LinkedIn DM, as the content kit does; nothing reads as edited.
        ->and($messages['whatsapp']['text'])->toBe($messages['linkedin_dm']['text'])
        ->and(collect($messages)->every(fn (array $m): bool => $m['edited'] === false))->toBeTrue();
});

it('lets messages pushed by the content kit win over the dm block', function () {
    pushStudioBatch($this, [studioGiveaway(['messages' => ['linkedin_dm' => 'Kit DM for {first_name}']])])->assertOk();

    $messages = Messages::for(ReviewItem::query()->sole());
    expect($messages['linkedin_dm']['text'])->toBe('Kit DM for {first_name}')
        ->and($messages['linkedin_connect']['text'])->toBe('Hi {first_name}, thanks for commenting PROMPTS. Accept this and I will send the pack.');
});

it('shows the DM, connection note, email and resource link on the post', function () {
    pushStudioBatch($this, [studioGiveaway()])->assertOk();
    $item = ReviewItem::query()->sole();

    $this->actingAs($this->owner)->get($item->shareUrl())->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.engine_post_id', 'AG3')
            ->where('items.0.resource.url', 'https://content.usetantra.com/r/prompt-pack')
            ->where('items.0.messages.linkedin_dm.text', "Hi {first_name}, here is the Prompt Pack:\nhttps://content.usetantra.com/r/prompt-pack")
            ->where('items.0.messages.linkedin_connect.text', 'Hi {first_name}, thanks for commenting PROMPTS. Accept this and I will send the pack.')
            ->where('items.0.messages.email_subject.text', 'Your Prompt Pack'));
});

it('gives the post in the field names the CRM asset import reads', function () {
    pushStudioBatch($this, [studioGiveaway()])->assertOk();

    $asset = $this->withHeaders($this->headers)->getJson(route('tantra.api.messages.show', ['engineBatchId' => '7', 'enginePostId' => 'AG3']))
        ->assertOk()
        ->json('data.asset');

    // tantra-crm assetFromStudio: id, profile, resource {name, keyword, what, url, pages, files}, dm {message, connect, email_subject, email_body}, first_comment, published.url.
    expect($asset['id'])->toBe('AG3')
        ->and($asset['profile'])->toBe('adi')
        ->and($asset['resource'])->toMatchArray(['name' => 'The Prompt Pack', 'keyword' => 'PROMPTS', 'url' => 'https://content.usetantra.com/r/prompt-pack'])
        ->and(array_keys($asset['dm']))->toContain('message', 'connect', 'email_subject', 'email_body')
        ->and($asset['dm']['connect'])->toBe('Hi {first_name}, thanks for commenting PROMPTS. Accept this and I will send the pack.')
        ->and($asset['first_comment'])->toBe('Comment PROMPTS below.');
});

it('skips posts without a resource, as the CRM does', function () {
    pushStudioBatch($this, [studioGiveaway(['resource' => null, 'dm' => null])])->assertOk();

    expect(EngagementAsset::record(ReviewItem::query()->sole()))->toBeNull();
});

it('sends imported giveaways to the CRM asset library, and the review owner edits after them', function () {
    config(['tantra.crm.api_key' => 'ck_test_key']);
    Http::fake(['crm.test/api/engagement/assets/import' => Http::response(['created' => 1, 'updated' => 0, 'skipped' => 0, 'ids' => ['ea_1'], 'assets' => []], 201)]);

    pushStudioBatch($this, [studioGiveaway(), studioGiveaway(['engine_post_id' => 'AG4', 'resource' => null, 'dm' => null])])->assertOk();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://crm.test/api/engagement/assets/import'
        && $r->hasHeader('Authorization', 'Bearer ck_test_key')
        && count($r['posts']) === 1
        && $r['posts'][0]['id'] === 'AG3'
        && $r['posts'][0]['dm']['message'] === "Hi {first_name}, here is the Prompt Pack:\nhttps://content.usetantra.com/r/prompt-pack"
        && $r['posts'][0]['resource']['url'] === 'https://content.usetantra.com/r/prompt-pack');

    $item = ReviewItem::query()->where('engine_post_id', 'AG3')->sole();
    $this->actingAs($this->owner)->postJson("/tantra/review/items/{$item->id}/messages/linkedin_connect", ['text' => 'Edited note for {first_name}'])->assertOk();

    Http::assertSent(fn (Request $r) => ($r['posts'][0]['dm']['connect'] ?? null) === 'Edited note for {first_name}');
});

it('sends nothing to the CRM without a key or for another Account', function () {
    Http::fake();
    pushStudioBatch($this, [studioGiveaway()])->assertOk();

    config(['tantra.crm.api_key' => 'ck_test_key']);
    $other = User::factory()->create();
    $ws = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    expect(SyncEngagementAssets::enabledFor($ws->account_id))->toBeFalse()
        ->and(SyncEngagementAssets::enabledFor($this->owner->account_id))->toBeTrue();

    Http::assertNothingSent();
});
