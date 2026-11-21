<?php

declare(strict_types=1);

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\PostTemplate;
use App\Models\RssFeed;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Gateway\GatewaySignature;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Manual\ManualTasks;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// tantra: gateway parity (PRD content engine redesign 4.1): review comments and sign-off, post by hand, the media library,
// link preview, Tantra ideas, labels, signatures, feeds and post templates over the signed gateway.

const PARITY_SECRET = 'parity-gateway-secret-0123456789abcdef-xyz';
const PARITY_CLERK = 'user_ParityGateway0123456';

/** @param array<string, mixed> $body */
function parityCall(object $test, string $method, string $path, array $body = [], string $scopes = 'content:read,content:write,content:publish'): TestResponse
{
    $raw = $body === [] ? '' : json_encode($body);

    return parityRaw($test, $method, $path, $raw, 'application/json', [], $scopes);
}

/** @param array<string, mixed> $files */
function parityRaw(object $test, string $method, string $path, string $raw, string $contentType, array $files = [], string $scopes = 'content:read,content:write,content:publish'): TestResponse
{
    $sig = GatewaySignature::sign(PARITY_SECRET, 'k1', time(), 'ws_crm_team', PARITY_CLERK, $scopes, $method, $path, $raw);

    return $test->call($method, $path, [], [], $files, [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TANTRA_SIGNATURE' => $sig,
        'HTTP_X_TANTRA_WORKSPACE' => 'ws_crm_team',
        'HTTP_X_TANTRA_MEMBER' => PARITY_CLERK,
        'HTTP_X_TANTRA_SCOPES' => $scopes,
    ], $raw);
}

/** @return array<string, mixed> */
function parityIdea(string $key = 'idea-1'): array
{
    return ['external_key' => $key, 'platform' => 'linkedin', 'title' => "Idea {$key}", 'hook' => 'Most funnels leak.', 'preview' => ['text' => 'Post body.'], 'kind' => 'new'];
}

beforeEach(function () {
    Cache::flush();
    Storage::fake(config('filesystems.default'));
    config(['tantra_gateway.enabled' => true, 'tantra_gateway.secrets' => ['k1' => PARITY_SECRET]]);
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->user->forceFill(['clerk_user_id' => PARITY_CLERK])->save();
    WorkspaceSettings::query()->create(['workspace_id' => $this->workspace->id, 'brand_profile_key' => 'parity', 'review_owner_user_id' => $this->user->id]);
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
});

describe('review comments and sign off', function () {
    beforeEach(function () {
        ImportBatch::execute($this->user, ['engine_batch_id' => 'p1', 'posts' => [['engine_post_id' => 'C1', 'profile' => 'parity', 'platform' => 'linkedin', 'text' => 'Waiting for you.', 'numbers_for_signoff' => ['400 brands']]]]);
        $this->item = ReviewItem::query()->where('engine_post_id', 'C1')->sole();
        $this->base = "/api/gateway/tantra/review/{$this->item->id}";
    });

    it('adds a comment and lists it with the numbers to sign off', function () {
        parityCall($this, 'POST', "{$this->base}/comments", ['body' => 'Check the second line.'])->assertOk()->assertJsonPath('data.ref', $this->item->id);
        parityCall($this, 'POST', "{$this->base}/comments", ['body' => ''])->assertStatus(422);

        $data = parityCall($this, 'GET', "{$this->base}/comments", scopes: 'content:read')->assertOk()->json('data');

        expect($data['comments'])->toHaveCount(1)
            ->and($data['comments'][0]['body'])->toBe('Check the second line.')
            ->and($data['comments'][0]['by'])->toBe($this->user->name)
            ->and($data['comments'][0])->not->toHaveKey('id')
            ->and($data['numbers_for_signoff'][0])->toMatchArray(['index' => 0, 'text' => '400 brands', 'signed' => false, 'can_sign' => true]);
    });

    it('needs the write scope to comment and the read scope to list', function () {
        parityCall($this, 'POST', "{$this->base}/comments", ['body' => 'Hi'], scopes: 'content:read')->assertForbidden();
        parityCall($this, 'GET', "{$this->base}/comments", scopes: 'content:write')->assertForbidden();
    });

    it('sends a post back with a note', function () {
        parityCall($this, 'POST', "{$this->base}/request-changes", [])->assertStatus(422);
        parityCall($this, 'POST', "{$this->base}/request-changes", ['note' => 'Shorter hook.'], scopes: 'content:read')->assertForbidden();
        parityCall($this, 'POST', "{$this->base}/request-changes", ['note' => 'Shorter hook.'], scopes: 'content:read,content:write')->assertOk()->assertJsonPath('data.status', 'changes_requested');

        expect($this->item->refresh()->review_status)->toBe(ReviewStatus::ChangesRequested);
        $comments = parityCall($this, 'GET', "{$this->base}/comments")->json('data.comments');
        expect($comments[0])->toMatchArray(['kind' => 'request_changes', 'body' => 'Shorter hook.']);
    });

    it('signs off a number with the publish scope', function () {
        parityCall($this, 'POST', "{$this->base}/sign-off", ['index' => 0], scopes: 'content:read,content:write')->assertForbidden();
        parityCall($this, 'POST', "{$this->base}/sign-off", ['index' => 9])->assertStatus(422);
        parityCall($this, 'POST', "{$this->base}/sign-off", ['index' => 0])->assertOk();

        expect($this->item->refresh()->numbers_for_signoff[0]['signed_at'])->not->toBeNull();
        parityCall($this, 'GET', "{$this->base}/comments")->assertJsonPath('data.numbers_for_signoff.0.signed', true);
    });

    it('takes an approval back, needing the publish scope', function () {
        $weekdays = PostingSchedule::empty();
        foreach ([0, 1, 2, 3, 4, 5, 6] as $day) {
            $weekdays = $weekdays->withTime($day, '08:30');
        }
        $this->channel->update(['posting_schedule' => $weekdays, 'timezone' => 'UTC']);
        parityCall($this, 'POST', "{$this->base}/sign-off", ['index' => 0])->assertOk();
        parityCall($this, 'POST', "{$this->base}/approve")->assertOk();
        expect($this->item->refresh()->review_status)->toBeIn([ReviewStatus::Approved, ReviewStatus::Scheduled]);

        parityCall($this, 'POST', "{$this->base}/unapprove", scopes: 'content:read,content:write')->assertForbidden();
        parityCall($this, 'POST', "{$this->base}/unapprove")->assertOk()->assertJsonPath('data.status', 'in_review');
        expect($this->item->refresh()->review_status)->toBe(ReviewStatus::InReview);
    });

    it('answers 404 for a post that does not exist', function () {
        parityCall($this, 'GET', '/api/gateway/tantra/review/00000000-0000-4000-8000-000000000000/comments')->assertNotFound();
        parityCall($this, 'POST', '/api/gateway/tantra/review/00000000-0000-4000-8000-000000000000/comments', ['body' => 'Hi'])->assertNotFound();
    });
});

describe('post by hand', function () {
    beforeEach(function () {
        ImportBatch::execute($this->user, ['engine_batch_id' => 'p3', 'posts' => [['engine_post_id' => 'X1', 'profile' => 'parity', 'platform' => 'x', 'text' => 'An X post.', 'thread' => ['Second.']]]]);
        $this->item = ReviewItem::query()->where('engine_post_id', 'X1')->sole();
        $this->task = ManualTasks::createFor($this->item);
    });

    it('lists the tasks without batch or engine ids', function () {
        $rows = parityCall($this, 'GET', '/api/gateway/tantra/tasks', scopes: 'content:read')->assertOk()->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['ref' => $this->task->id, 'kind' => 'x_thread', 'status' => 'waiting', 'text' => 'An X post.', 'thread' => ['Second.'], 'review_ref' => $this->item->id, 'can_mark' => true])
            ->and($rows[0])->not->toHaveKeys(['id', 'engine_post_id', 'workspace_id', 'review_url']);

        parityCall($this, 'GET', '/api/gateway/tantra/tasks?status=posted')->assertOk()->assertJsonCount(0, 'data');
        parityCall($this, 'GET', '/api/gateway/tantra/tasks?status=nonsense')->assertStatus(422);
        parityCall($this, 'GET', '/api/gateway/tantra/tasks', scopes: 'content:write')->assertForbidden();
    });

    it('marks a task posted with a link on X or LinkedIn only', function () {
        $path = "/api/gateway/tantra/tasks/{$this->task->id}/posted";
        parityCall($this, 'POST', $path, ['url' => 'https://example.com/x'])->assertStatus(422);
        parityCall($this, 'POST', $path, ['url' => 'https://x.com/someone/status/1'], scopes: 'content:read')->assertForbidden();
        parityCall($this, 'POST', $path, ['url' => 'https://x.com/someone/status/1'], scopes: 'content:write')->assertOk()->assertJsonPath('data.status', 'posted');

        expect($this->task->refresh()->status)->toBe('posted')
            ->and($this->item->refresh()->review_status)->toBe(ReviewStatus::Published);
    });

    it('skips a task', function () {
        parityCall($this, 'POST', "/api/gateway/tantra/tasks/{$this->task->id}/skip")->assertOk()->assertJsonPath('data.status', 'skipped');
        expect($this->task->refresh()->status)->toBe('skipped');
    });

    it('answers 404 for a task of another workspace', function () {
        $other = Workspace::factory()->create(['account_id' => $this->workspace->account_id]);
        ManualTask::query()->where('id', $this->task->id)->update(['workspace_id' => $other->id]);

        parityCall($this, 'POST', "/api/gateway/tantra/tasks/{$this->task->id}/skip")->assertNotFound();
    });
});

describe('media library', function () {
    it('uploads a file as a media row that post create can use, and lists it', function () {
        $file = UploadedFile::fake()->image('cover.png', 40, 40);
        $boundary = 'parityboundary';
        $raw = "--{$boundary}\r\nContent-Disposition: form-data; name=\"media\"; filename=\"cover.png\"\r\nContent-Type: image/png\r\n\r\n".file_get_contents($file->getPathname())."\r\n--{$boundary}--\r\n";

        $data = parityRaw($this, 'POST', '/api/gateway/tantra/media', $raw, "multipart/form-data; boundary={$boundary}", ['media' => $file])->assertCreated()->json('data');

        expect($data)->toHaveKeys(['id', 'upload_token', 'url', 'type', 'mime_type', 'size', 'expires_at'])
            ->and($data['type'])->toBe('image')
            ->and(ResolveWorkspaceMedia::execute($this->workspace, [$data['id']])->has($data['id']))->toBeTrue()
            ->and(ResolveWorkspaceMedia::byUploadTokens($this->workspace, [$data['upload_token']])->has($data['upload_token']))->toBeTrue();

        $list = parityCall($this, 'GET', '/api/gateway/tantra/media', scopes: 'content:read')->assertOk()->json();
        expect($list['data'])->toHaveCount(1)
            ->and($list['data'][0]['id'])->toBe($data['id'])
            ->and($list['data'][0])->not->toHaveKeys(['upload_token', 'path', 'workspace_id'])
            ->and($list['meta'])->toMatchArray(['page' => 1, 'total' => 1]);
    });

    it('needs the write scope to upload', function () {
        $file = UploadedFile::fake()->image('cover.png');
        parityRaw($this, 'POST', '/api/gateway/tantra/media', 'x', 'multipart/form-data; boundary=b', ['media' => $file], 'content:read')->assertForbidden();
    });

    it('pages the library, filters by type and keeps other workspaces out', function () {
        foreach (range(1, 3) as $i) {
            $this->workspace->addMedia(UploadedFile::fake()->image("p{$i}.png"), Media::COLLECTION_UPLOADS);
        }
        $this->workspace->addMedia(UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'), Media::COLLECTION_UPLOADS);
        $other = Workspace::factory()->create();
        $other->addMedia(UploadedFile::fake()->image('theirs.png'), Media::COLLECTION_UPLOADS);

        $page = parityCall($this, 'GET', '/api/gateway/tantra/media?per_page=2&page=2', scopes: 'content:read')->assertOk()->json();
        expect($page['data'])->toHaveCount(2)->and($page['meta'])->toMatchArray(['page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2]);

        parityCall($this, 'GET', '/api/gateway/tantra/media?type=image')->assertOk()->assertJsonCount(3, 'data');
        parityCall($this, 'GET', '/api/gateway/tantra/media?type=bogus')->assertStatus(422);
    });

    it('sets and clears the alt text of an image', function () {
        $media = $this->workspace->addMedia(UploadedFile::fake()->image('a.png'), Media::COLLECTION_UPLOADS);
        $path = "/api/gateway/tantra/media/{$media->id}";

        parityCall($this, 'PATCH', $path, ['alt_text' => 'A red door'])->assertOk()->assertJsonPath('data.alt_text', 'A red door');
        expect($media->refresh()->meta['alt_text'])->toBe('A red door');
        parityCall($this, 'GET', '/api/gateway/tantra/media')->assertJsonPath('data.0.alt_text', 'A red door');

        parityCall($this, 'PATCH', $path, ['alt_text' => null])->assertOk()->assertJsonPath('data.alt_text', null);
        expect($media->refresh()->meta ?? [])->not->toHaveKey('alt_text');
        parityCall($this, 'PATCH', $path, ['alt_text' => 'x'], scopes: 'content:read')->assertForbidden();
        parityCall($this, 'PATCH', $path, [])->assertStatus(422);
    });

    it('answers 404 for another workspace media and 422 for alt text on a video', function () {
        $other = Workspace::factory()->create();
        $theirs = $other->addMedia(UploadedFile::fake()->image('t.png'), Media::COLLECTION_UPLOADS);
        $video = $this->workspace->addMedia(UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'), Media::COLLECTION_UPLOADS);

        parityCall($this, 'PATCH', "/api/gateway/tantra/media/{$theirs->id}", ['alt_text' => 'x'])->assertNotFound();
        parityCall($this, 'PATCH', "/api/gateway/tantra/media/{$video->id}", ['alt_text' => 'x'])->assertStatus(422);
    });
});

describe('link preview', function () {
    it('answers the title, description and image of a page', function () {
        Http::fake(['https://example.com' => Http::response('<html><head><meta property="og:title" content="Example"><meta property="og:description" content="Desc"><meta property="og:image" content="https://example.com/card.png"></head></html>', 200)]);

        parityCall($this, 'GET', '/api/gateway/tantra/link-preview?url='.urlencode('https://example.com'), scopes: 'content:read')->assertOk()
            ->assertJsonPath('data.title', 'Example')->assertJsonPath('data.description', 'Desc')
            ->assertJsonPath('data.image', 'https://example.com/card.png')->assertJsonPath('data.domain', 'example.com');
    });

    it('keeps the composer SSRF protection: a private address gets no card and no request', function () {
        Http::fake();

        parityCall($this, 'GET', '/api/gateway/tantra/link-preview?url='.urlencode('http://127.0.0.1/private'))->assertOk()->assertJsonPath('data', null);
        parityCall($this, 'GET', '/api/gateway/tantra/link-preview?url='.urlencode('http://169.254.169.254/latest/meta-data'))->assertOk()->assertJsonPath('data', null);
        Http::assertNothingSent();
    });

    it('needs a url and the read scope', function () {
        parityCall($this, 'GET', '/api/gateway/tantra/link-preview')->assertStatus(422);
        parityCall($this, 'GET', '/api/gateway/tantra/link-preview?url='.urlencode('https://example.com'), scopes: 'content:write')->assertForbidden();
    });
});

describe('tantra ideas', function () {
    it('creates, updates by key, lists, patches and deletes an idea of the pinned workspace', function () {
        $created = parityCall($this, 'POST', '/api/gateway/tantra/ideas', parityIdea())->assertCreated()->assertJsonPath('outcome', 'created')->json('data');

        expect($created['ref'])->toBeString()
            ->and($created)->not->toHaveKeys(['id', 'workspace_id', 'channel_id', 'agent_job_id', 'source_post_id', 'source_review_item_id', 'review_item_id', 'review_url'])
            ->and($created['title'])->toBe('Idea idea-1')
            ->and($created['preview']['text'])->toBe('Post body.');

        parityCall($this, 'POST', '/api/gateway/tantra/ideas', [...parityIdea(), 'title' => 'Renamed'])->assertOk()->assertJsonPath('outcome', 'updated')->assertJsonPath('data.ref', $created['ref']);
        expect(IdeaMeta::query()->count())->toBe(1);

        $list = parityCall($this, 'GET', '/api/gateway/tantra/ideas', scopes: 'content:read')->assertOk()->json();
        expect($list['data'])->toHaveCount(1)->and($list['data'][0]['title'])->toBe('Renamed')->and($list['meta']['total'])->toBe(1);

        parityCall($this, 'PATCH', "/api/gateway/tantra/ideas/{$created['ref']}", ['hook' => 'A new hook.'])->assertOk()->assertJsonPath('data.hook', 'A new hook.');
        parityCall($this, 'DELETE', "/api/gateway/tantra/ideas/{$created['ref']}")->assertOk();
        expect(IdeaMeta::query()->count())->toBe(0);
    });

    it('ignores a workspace or profile in the body: the idea lands in the pinned workspace', function () {
        $other = Workspace::factory()->create(['account_id' => $this->workspace->account_id]);
        $ref = parityCall($this, 'POST', '/api/gateway/tantra/ideas', [...parityIdea(), 'workspace_id' => $other->id, 'profile' => 'nope'])->assertCreated()->json('data.ref');

        expect(IdeaMeta::query()->findOrFail($ref)->idea->workspace_id)->toBe($this->workspace->id);
    });

    it('sends an idea to Review once and returns the review ref', function () {
        $ref = parityCall($this, 'POST', '/api/gateway/tantra/ideas', parityIdea())->json('data.ref');

        $first = parityCall($this, 'POST', "/api/gateway/tantra/ideas/{$ref}/to-review")->assertOk()->json();
        $again = parityCall($this, 'POST', "/api/gateway/tantra/ideas/{$ref}/to-review")->assertOk()->json();

        expect($first['review_ref'])->toBe($again['review_ref'])
            ->and(ReviewItem::query()->findOrFail($first['review_ref'])->workspace_id)->toBe($this->workspace->id)
            ->and($first['data']['state'])->toBe('in_review');
    });

    it('answers 404 for an idea of another workspace, and checks scopes', function () {
        $ref = parityCall($this, 'POST', '/api/gateway/tantra/ideas', parityIdea())->json('data.ref');
        $meta = IdeaMeta::query()->findOrFail($ref);
        $meta->idea->forceFill(['workspace_id' => Workspace::factory()->create(['account_id' => $this->workspace->account_id])->id])->save();

        parityCall($this, 'PATCH', "/api/gateway/tantra/ideas/{$ref}", ['hook' => 'x'])->assertNotFound();
        parityCall($this, 'DELETE', "/api/gateway/tantra/ideas/{$ref}")->assertNotFound();
        parityCall($this, 'POST', "/api/gateway/tantra/ideas/{$ref}/to-review")->assertNotFound();
        parityCall($this, 'POST', '/api/gateway/tantra/ideas', parityIdea('k2'), scopes: 'content:read')->assertForbidden();
        parityCall($this, 'GET', '/api/gateway/tantra/ideas', scopes: 'content:write')->assertForbidden();
    });

    it('validates the idea', function () {
        parityCall($this, 'POST', '/api/gateway/tantra/ideas', ['platform' => 'linkedin'])->assertStatus(422);
        parityCall($this, 'POST', '/api/gateway/tantra/ideas', [...parityIdea(), 'platform' => 'nowhere'])->assertStatus(422);
    });
});

describe('labels, signatures, feeds and templates', function () {
    it('lists the labels and signatures of the workspace', function () {
        $this->workspace->labels()->create(['name' => 'Launch', 'color' => '#ff0000']);
        $this->workspace->signatures()->create(['name' => 'Sign off', 'content' => '-- Adi']);

        parityCall($this, 'GET', '/api/gateway/labels', scopes: 'content:read')->assertOk()->assertJsonFragment(['name' => 'Launch']);
        parityCall($this, 'GET', '/api/gateway/signatures', scopes: 'content:read')->assertOk()->assertJsonFragment(['name' => 'Sign off']);
        parityCall($this, 'GET', '/api/gateway/labels', scopes: 'content:write')->assertForbidden();
    });

    it('lists the feeds of the workspace only', function () {
        RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Our feed']);
        RssFeed::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'title' => 'Their feed']);

        $rows = parityCall($this, 'GET', '/api/gateway/tantra/feeds', scopes: 'content:read')->assertOk()->json('data');

        expect($rows)->toHaveCount(1)->and($rows[0]['title'])->toBe('Our feed')->and($rows[0])->not->toHaveKeys(['id', 'workspace_id']);
        parityCall($this, 'GET', '/api/gateway/tantra/feeds', scopes: 'content:write')->assertForbidden();
    });

    it('lists the team templates and the member own, not another member personal ones', function () {
        $mate = User::factory()->create(['account_id' => $this->user->account_id]);
        PostTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $mate->id, 'visibility' => 'team', 'title' => 'Team one']);
        PostTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'visibility' => 'personal', 'title' => 'Mine']);
        PostTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $mate->id, 'visibility' => 'personal', 'title' => 'Theirs']);

        $rows = parityCall($this, 'GET', '/api/gateway/tantra/post-templates', scopes: 'content:read')->assertOk()->json('data');

        expect(collect($rows)->pluck('title')->sort()->values()->all())->toBe(['Mine', 'Team one'])
            ->and($rows[0])->not->toHaveKeys(['id', 'workspace_id', 'user_id']);
    });
});
