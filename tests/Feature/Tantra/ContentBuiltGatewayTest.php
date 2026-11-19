<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Create\CreatePiece;
use App\Tantra\Gateway\GatewaySignature;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Templates\ContentTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// tantra: CR-06, a post the Tantra CRM built itself, imported into Review as it is (ImportBuilt over GatewayContentController).

const BUILT_GW_SECRET = 'built-gateway-secret-0123456789abcdef-xyz';
const BUILT_GW_CLERK = 'user_BuiltGateway012345678';
const BUILT_HOST = '93.184.216.34';

/** @param array<string, mixed> $body */
function builtGatewayCall(object $test, string $method, string $path, array $body = [], string $scopes = 'content:read,content:write,content:publish'): TestResponse
{
    $raw = $body === [] ? '' : json_encode($body);
    $sig = GatewaySignature::sign(BUILT_GW_SECRET, 'k1', time(), 'ws_crm_team', BUILT_GW_CLERK, $scopes, $method, $path, $raw);

    return $test->call($method, $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TANTRA_SIGNATURE' => $sig,
        'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team',
        'HTTP_X_TANTRA_MEMBER' => BUILT_GW_CLERK,
        'HTTP_X_TANTRA_SCOPES' => $scopes,
    ], $raw);
}

function builtUrl(string $name): string
{
    return 'https://'.BUILT_HOST.'/content/built/'.$name;
}

function builtPdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/** @return array<string, mixed> */
function builtCarousel(string $text = 'Five steps to a week of posts.'): array
{
    return [
        'title' => 'A week of posts',
        'text' => $text,
        'first_comment' => 'The full guide is in the comments.',
        'media' => ['type' => 'carousel', 'pages' => [builtUrl('p1.png'), builtUrl('p2.png')], 'pdf' => builtUrl('carousel.pdf'), 'mp4' => null, 'gif' => null, 'image' => null],
    ];
}

beforeEach(function () {
    Cache::flush();
    Storage::fake();
    Queue::fake();
    config(['tantra_gateway.enabled' => true, 'tantra_gateway.secrets' => ['k1' => BUILT_GW_SECRET], 'tantra.built.media_hosts' => [BUILT_HOST]]);
    $png = file_get_contents(base_path('tests/fixtures/1x1.png'));
    Http::fake([
        'https://'.BUILT_HOST.'/content/built/*.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        'https://'.BUILT_HOST.'/content/built/*.pdf' => Http::response(builtPdf(), 200, ['Content-Type' => 'application/pdf']),
        'https://'.BUILT_HOST.'/content/built/*.mp4' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4')), 200, ['Content-Type' => 'video/mp4']),
    ]);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->user->forceFill(['clerk_user_id' => BUILT_GW_CLERK])->save();
    WorkspaceSettings::query()->create(['workspace_id' => $this->workspace->id, 'brand_profile_key' => 'built', 'review_owner_user_id' => $this->user->id]);
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    ContentTemplate::query()->forceCreate([
        'key' => 'LI7', 'title' => 'The five slide carousel', 'platform' => 'linkedin', 'format' => 'carousel',
        'description' => 'Five slides.', 'spec' => 'Slide one is the hook.', 'copy_template' => 'Hook.', 'status' => 'live', 'origin' => 'seed', 'account_id' => null,
    ]);
});

describe('create with built', function () {
    it('imports the finished piece into Review as it is, with no writing step', function () {
        $r = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'One call fills a week', 'platform' => 'linkedin', 'built' => builtCarousel()])
            ->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.format', 'LI7')->assertJsonPath('data.platform', 'linkedin');

        Queue::assertNotPushed(CreatePiece::class);
        $item = ReviewItem::query()->findOrFail($r->json('data.review_ref'));
        $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

        expect($item->review_status)->toBe(ReviewStatus::InReview)
            ->and($item->workspace_id)->toBe($this->workspace->id)
            ->and($item->engine_batch_id)->toStartWith('create-')
            ->and($item->payload['text'])->toBe('Five steps to a week of posts.')
            ->and($item->payload['first_comment'])->toBe('The full guide is in the comments.')
            ->and($item->payload['sample']['id'])->toBe('LI7')
            ->and($item->short)->toBe('A week of posts')
            ->and($post->content)->toBe('Five steps to a week of posts.')
            // A LinkedIn carousel goes as its PDF, the pages stay the preview.
            ->and(collect($post->media)->pluck('mime_type')->all())->toBe(['application/pdf'])
            ->and($r->json('data'))->not->toHaveKeys(['user_id', 'workspace_id', 'idea_full']);

        $review = builtGatewayCall($this, 'GET', '/api/gateway/tantra/review')->assertOk()->json('data.items.0');
        expect($review['ref'])->toBe($item->id)
            ->and($review['images'])->toBe([builtUrl('p1.png'), builtUrl('p2.png')]);

        builtGatewayCall($this, 'GET', '/api/gateway/tantra/create/'.$r->json('data.ref'))->assertOk()->assertJsonPath('data.review_ref', $item->id);
        builtGatewayCall($this, 'GET', '/api/gateway/tantra/create')->assertOk()->assertJsonCount(1, 'data');
    });

    it('keeps a carousel for another network as its pages, in page order', function () {
        $r = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Pages', 'platform' => 'instagram', 'built' => builtCarousel()])->assertCreated();
        $item = ReviewItem::query()->findOrFail($r->json('data.review_ref'));
        $held = (array) $item->media_manifest['held_tokens'];

        expect($held)->toHaveCount(2)
            ->and(Media::query()->whereIn('upload_token', $held)->orderBy('created_at')->pluck('original_filename')->all())->toBe(['p1.png', 'p2.png']);
    });

    it('takes a video post as its MP4', function () {
        $built = ['text' => 'Watch this.', 'media' => ['type' => 'video', 'mp4' => builtUrl('anim.mp4')]];
        $r = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'A video', 'built' => $built])->assertCreated();
        $item = ReviewItem::query()->findOrFail($r->json('data.review_ref'));

        expect(collect(Post::query()->where('post_group_id', $item->post_group_id)->sole()->media)->pluck('mime_type')->all())->toBe(['video/mp4']);
    });

    it('lands a retry of the same piece on the same Review item', function () {
        $body = ['format' => 'LI7', 'idea' => 'Same', 'built' => builtCarousel()];
        $first = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', $body)->assertCreated()->json('data.review_ref');
        $second = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', $body)->assertCreated()->json('data.review_ref');

        expect($second)->toBe($first)->and(ReviewItem::query()->count())->toBe(1);
    });

    it('refuses media off the allowed hosts, plain http and too many pages, before any download', function (array $media, string $error) {
        $built = ['text' => 'Copy.', 'media' => $media];
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Bad media', 'built' => $built])
            ->assertStatus(422)->assertJsonValidationErrors($error);

        Http::assertNothingSent();
        expect(ReviewItem::query()->count())->toBe(0)->and(Media::query()->count())->toBe(0);
    })->with([
        'other host' => [['type' => 'image', 'image' => 'https://evil.example.com/a.png'], 'built.media.image'],
        'plain http' => [['type' => 'image', 'image' => 'http://'.BUILT_HOST.'/content/built/a.png'], 'built.media.image'],
        'user in url' => [['type' => 'image', 'image' => 'https://me:pw@'.BUILT_HOST.'/content/built/a.png'], 'built.media.image'],
        'too many pages' => [['type' => 'carousel', 'pages' => array_map(fn (int $i): string => builtUrl("p{$i}.png"), range(1, 21))], 'built.media.pages'],
        'unknown type' => [['type' => 'hologram'], 'built.media.type'],
    ]);

    it('refuses all built media when no host is allowed', function () {
        config(['tantra.built.media_hosts' => []]);
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'built' => ['text' => 'Copy.', 'media' => ['type' => 'image', 'image' => builtUrl('a.png')]]])
            ->assertStatus(422)->assertJsonValidationErrors('built.media.image');
    });

    it('refuses a file of the wrong kind and removes what it downloaded', function () {
        $built = ['text' => 'Copy.', 'media' => ['type' => 'carousel', 'pages' => [builtUrl('p1.png'), builtUrl('fake.pdf')]]];
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'platform' => 'instagram', 'built' => $built])
            ->assertStatus(422)->assertJsonValidationErrors('built.media');

        expect(ReviewItem::query()->count())->toBe(0)->and(Media::query()->count())->toBe(0);
    });

    it('refuses a file over the size cap', function () {
        config(['tantra.built.max_file_mb' => 1]);
        Http::fake(['https://'.BUILT_HOST.'/content/built/big.png' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')).str_repeat("\0", 1024 * 1024 + 10), 200)]);
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'built' => ['text' => 'Copy.', 'media' => ['type' => 'image', 'image' => builtUrl('big.png')]]])
            ->assertStatus(422)->assertJsonValidationErrors('built.media');

        expect(Media::query()->count())->toBe(0);
    });

    it('needs the copy, a known format and the write scope', function () {
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'built' => ['media' => ['type' => 'text']]])->assertStatus(422)->assertJsonValidationErrors('built.text');
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'ZZ9', 'built' => ['text' => 'Copy.']])->assertStatus(422)->assertJsonValidationErrors('format');
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'built' => ['text' => 'Copy.']], scopes: 'content:read')->assertForbidden();
    });

    it('takes out en and em dashes like Create does', function () {
        $r = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'built' => ['text' => "One call \u{2014} five posts.", 'media' => ['type' => 'text']]])->assertCreated();

        expect(ReviewItem::query()->findOrFail($r->json('data.review_ref'))->payload['text'])->toBe('One call, five posts.');
    });

    it('sends a named channel of the workspace, and never one of another workspace', function () {
        $page = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Acme Page']);
        $elsewhere = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create(['account_id' => $this->user->account_id])->id, 'platform' => Platform::LinkedIn]);

        $named = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Named', 'built' => ['text' => 'To the page.', 'account' => 'Acme Page']])->assertCreated()->json('data.review_ref');
        $foreign = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Foreign', 'built' => ['text' => 'Not there.', 'account' => $elsewhere->id]])->assertCreated()->json('data.review_ref');

        expect(Post::query()->where('post_group_id', ReviewItem::query()->findOrFail($named)->post_group_id)->sole()->social_account_id)->toBe($page->id)
            ->and(Post::query()->where('post_group_id', ReviewItem::query()->findOrFail($foreign)->post_group_id)->sole()->social_account_id)->toBe($this->channel->id);
    });

    it('keeps today\'s Create when built is not sent', function () {
        builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Write it for me'])->assertStatus(202)->assertJsonPath('data.status', 'creating');

        Queue::assertPushed(CreatePiece::class);
        expect(ReviewItem::query()->count())->toBe(0);
    });
});

describe('edit with built', function () {
    beforeEach(function () {
        $ref = builtGatewayCall($this, 'POST', '/api/gateway/tantra/create', ['format' => 'LI7', 'idea' => 'Edit me', 'built' => builtCarousel()])->assertCreated()->json('data.review_ref');
        $this->item = ReviewItem::query()->findOrFail($ref);
    });

    it('replaces the copy and the media of the same Review item', function () {
        $built = ['title' => 'Sharper', 'text' => 'A sharper hook.', 'first_comment' => null, 'media' => ['type' => 'image', 'pages' => [], 'image' => builtUrl('new.png')]];
        builtGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$this->item->id}", ['text' => 'A sharper hook.', 'built' => $built])
            ->assertOk()->assertJsonPath('data.ref', $this->item->id)->assertJsonPath('data.text', 'A sharper hook.');

        $item = $this->item->refresh();
        $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

        expect(ReviewItem::query()->count())->toBe(1)
            ->and($item->revision)->toBe(2)
            ->and($item->short)->toBe('Sharper')
            ->and($item->payload)->not->toHaveKey('first_comment')
            ->and($item->payload['sample']['id'])->toBe('LI7')
            ->and($post->content)->toBe('A sharper hook.')
            ->and(collect($post->media)->pluck('mime_type')->all())->toBe(['image/png']);
    });

    it('keeps a text only edit as today', function () {
        builtGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$this->item->id}", ['text' => 'Only words.'])->assertOk()->assertJsonPath('data.text', 'Only words.');

        expect($this->item->refresh()->revision)->toBe(1);
    });

    it('refuses a post that does not wait for review any more', function () {
        $this->item->forceFill(['review_status' => ReviewStatus::Approved])->save();
        builtGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$this->item->id}", ['built' => ['text' => 'Too late.']])->assertStatus(422);
    });

    it('refuses built media on a post not made in Create', function () {
        ImportBatch::execute($this->user, ['engine_batch_id' => 'b9', 'posts' => [['engine_post_id' => 'E1', 'profile' => 'built', 'platform' => 'linkedin', 'text' => 'From a batch.']]]);
        $other = ReviewItem::query()->where('engine_post_id', 'E1')->sole();

        builtGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$other->id}", ['built' => ['text' => 'Swap.']])->assertStatus(422)->assertJsonValidationErrors('built');
        expect($other->refresh()->payload['text'])->toBe('From a batch.');
    });

    it('answers 404 for a post of another account', function () {
        $stranger = User::factory()->create();
        $theirs = ReviewItem::query()->whereKey($this->item->id)->first();
        $theirs->forceFill(['account_id' => $stranger->account_id])->save();

        builtGatewayCall($this, 'PUT', "/api/gateway/tantra/review/{$this->item->id}", ['built' => ['text' => 'Not mine.']])->assertNotFound();
    });
});
