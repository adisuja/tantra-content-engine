<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\Idea;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Links\LinkRules;
use App\Tantra\Links\Piece;
use App\Tantra\Links\PieceLinks;
use App\Tantra\Links\TrackedLink;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// tantra: every link in content becomes a CRM short link for its piece and person (card 29 rule).

function fakeCrm(array &$made): void
{
    Http::fake(function (Request $r) use (&$made) {
        if (parse_url($r->url(), PHP_URL_HOST) !== 'crm.test') {
            return null;
        }
        $path = parse_url($r->url(), PHP_URL_PATH);
        if ($r->method() === 'POST' && str_ends_with($path, '/channels')) {
            return $r['channel'] === 'facebook' && ! cache()->has('vocab-added')
                ? Http::response(['error' => 'not in the vocabulary', 'code' => 'unknown_vocab'], 422)
                : Http::response(['channel' => ['id' => 'chn_'.$r['channel']]], 201);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/vocab')) {
            cache()->forever('vocab-added', true);

            return Http::response(['id' => 'voc_1'], 201);
        }
        if ($r->method() === 'GET' && str_ends_with($path, '/assets')) {
            return Http::response(['assets' => []]);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/assets')) {
            return Http::response(['asset' => ['id' => 'ast_'.substr(md5($r['ref_id']), 0, 6)]], 201);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/links')) {
            if (in_array($r['code'], array_column($made, 'code'), true) || $r['code'] === 'ben-draft-whole-funnel-comment') {
                return Http::response(['error' => 'taken', 'code' => 'code_taken'], 409);
            }
            $made[] = $r->data();

            return Http::response(['id' => 'lnk_'.count($made), 'code' => $r['code'], 'short_url' => 'https://go.usetantra.com/l/'.$r['code'], 'long_url' => $r['destination'].'?x=1'], 201);
        }

        return Http::response([], 404);
    });
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));
    Storage::fake('local');
    config(['tantra.crm.api_key' => 'k', 'tantra.crm.url' => 'https://crm.test']);
    $this->made = [];
    fakeCrm($this->made);

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'ben', 'review_owner_user_id' => $this->owner->id]);
    $schedule = PostingSchedule::empty()->withTime(1, '08:30');
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn, 'timezone' => 'UTC', 'posting_schedule' => $schedule]);
});

it('builds readable codes, destinations and UTMs by the card 29 rule', function () {
    expect(LinkRules::code('ben', 'Draft a whole funnel from one brief', 'linkedin', 'comment', 'comment'))->toBe('ben-draft-whole-funnel-comment')
        ->and(LinkRules::code('aquila', 'The first 48 hours after payment', 'facebook', 'comment', 'comment'))->toBe('aquila-first-48-hours-fb-comment')
        ->and(LinkRules::code('ben', 'Calendar invite blueprint', 'linkedin', 'dm', 'giveaway'))->toBe('ben-calendar-invite-dm')
        ->and(strlen(LinkRules::code('bhaskar', str_repeat('verylongword ', 9), 'pinterest', 'thread-2', 'comment', 3)))->toBeLessThanOrEqual(40)
        ->and(LinkRules::destination('https://webinar.sellwithtantra.com/?utm_source=x&ref=7#reg'))->toBe('https://jointantra.com/?ref=7#reg')
        ->and(LinkRules::destination('growthclub.org/webinar'))->toBe('https://jointantra.com/')
        ->and(LinkRules::destination('https://crm.usetantra.com/book/tantra/ai-revops-strategy-consultation'))->toBe('https://crm.usetantra.com/book/tantra/ai-revops-strategy-consultation')
        ->and(LinkRules::utm('unipile-linkedin', 'comment', 'ben-x'))->toBe(['utm_source' => 'linkedin', 'utm_medium' => 'comment', 'utm_campaign' => 'enterprise-webinar', 'utm_content' => 'ben-x'])
        ->and(LinkRules::urls('Register here: https://jointantra.com/?a=1. Or jointantra.com.'))->toBe(['https://jointantra.com/?a=1', 'jointantra.com'])
        ->and(PieceLinks::assetName(new Piece('aquila', null, 'ri:1', 'The first 48 hours after payment', 'facebook', 'post')))->toBe('aquila: The first 48 hours af fb')
        ->and(LinkRules::needsTracking('https://go.usetantra.com/l/ben-x', fn () => false))->toBeFalse()
        ->and(LinkRules::needsTracking('https://adisuja.github.io/tantra-revops/sheet/', fn () => false))->toBeTrue();
});

it('replaces the link in a pushed piece, its draft and its messages, keeping the copy word for word', function () {
    ImportBatch::execute($this->owner, ['engine_batch_id' => '4', 'title' => 'Batch 4', 'posts' => [[
        'engine_post_id' => 'EC1', 'profile' => 'ben', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'UTC',
        'short' => 'Draft a whole funnel from one brief', 'format' => 'Carousel · 9 pages',
        'text' => 'Set up Claude to draft a whole funnel.',
        'first_comment' => "We teach this in a free 60-minute live workshop. Register here:\nhttps://jointantra.com/?utm_source=linkedin&utm_medium=social&utm_campaign=enterprise-webinar&utm_content=EC1",
        'messages' => ['linkedin_dm' => "Hi {first_name}, here it is:\nhttps://adisuja.github.io/tantra-revops/sheet/", 'sms' => 'Hi {first_name}, the sheet: https://adisuja.github.io/tantra-revops/sheet/'],
    ]]]);

    $item = ReviewItem::query()->where('engine_post_id', 'EC1')->sole();
    $post = Post::query()->where('post_group_id', $item->post_group_id)->sole();

    // The ben-draft-whole-funnel-comment code is taken in the CRM, so the next one is used.
    expect($item->payload['first_comment'])->toBe("We teach this in a free 60-minute live workshop. Register here:\nhttps://go.usetantra.com/l/ben-draft-whole-funnel-comment-2")
        ->and($post->meta['thread_replies'][0]['text'])->toBe($item->payload['first_comment'])
        ->and($item->payload['messages']['linkedin_dm'])->toBe("Hi {first_name}, here it is:\nhttps://go.usetantra.com/l/ben-draft-whole-funnel-dm")
        ->and($item->payload['messages']['sms'])->toBe('Hi {first_name}, the sheet: https://go.usetantra.com/l/ben-draft-whole-funnel-sms')
        ->and($item->payload['text'])->toBe('Set up Claude to draft a whole funnel.');

    // One link per piece and placement: the draft and the review item share the comment link.
    expect(TrackedLink::query()->count())->toBe(3);
    $comment = collect($this->made)->firstWhere('placement', 'comment');
    expect($comment['destination'])->toBe('https://jointantra.com/')
        ->and($comment['channel_id'])->toBe('chn_c1118abc4dc74c06a10b')
        ->and($comment['campaign_id'])->toBe('cmp_f427ef8e47514047be1d')
        ->and($comment['append_params'])->toBe(['utm_source' => 'linkedin', 'utm_medium' => 'comment', 'utm_campaign' => 'enterprise-webinar', 'utm_content' => 'ben-draft-whole-funnel-comment-2'])
        ->and(collect($this->made)->firstWhere('placement', 'dm')['append_params']['utm_medium'])->toBe('giveaway');

    $csv = Storage::disk('local')->get(PieceLinks::LOG);
    expect($csv)->toContain('changed_at,subject_type,piece_id,member')
        ->and($csv)->toContain('utm_content=EC1')
        ->and($csv)->toContain('https://go.usetantra.com/l/ben-draft-whole-funnel-comment-2');

    // Running the sweep again changes nothing and makes no new links.
    $before = count($this->made);
    $this->artisan('tantra:track-links')->expectsOutputToContain('0 links replaced.')->assertSuccessful();
    expect(count($this->made))->toBe($before);
});

it('keeps the full jointantra.com UTM link in a LinkedIn post body that shows a preview card', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'status' => 'draft', 'platform' => Platform::LinkedIn,
        'content' => "Pipelines leak.\n\nSee it live: https://webinar.sellwithtantra.com/ and book: https://crm.usetantra.com/book/tantra/ai-revops-strategy-consultation",
    ]);

    $post->refresh();
    expect($post->content)->toStartWith("Pipelines leak.\n\nSee it live: https://jointantra.com/?utm_source=linkedin&utm_medium=post&utm_campaign=enterprise-webinar&utm_content=ben-pipelines-leak-see-post and book: https://go.usetantra.com/l/ben-pipelines-leak-see-post-2");

    // The kept full link is recognised as ours on the next save, so nothing changes.
    $before = count($this->made);
    $post->update(['content' => $post->content.' ']);
    expect(count($this->made))->toBe($before);
});

it('tracks an idea on another network as the same piece, with its own platform channel and UTMs', function () {
    ImportBatch::execute($this->owner, ['engine_batch_id' => '4', 'title' => 'Batch 4', 'posts' => [[
        'engine_post_id' => 'QC3', 'profile' => 'ben', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'UTC',
        'short' => 'The first 48 hours after payment', 'text' => 'Seven things.',
    ]]]);
    $source = ReviewItem::query()->where('engine_post_id', 'QC3')->sole();
    $idea = Idea::factory()->create(['workspace_id' => $this->ws->id, 'title' => 'The first 48 hours after payment (Facebook)']);
    IdeaMeta::query()->create([
        'idea_id' => $idea->id, 'account_id' => $this->owner->account_id, 'external_key' => 'b4-QC3-facebook', 'profile_key' => 'ben',
        'platform' => 'facebook', 'kind' => 'repurpose', 'state' => 'idea', 'source_review_item_id' => $source->id,
        'preview' => ['text' => 'Seven things.', 'first_comment' => 'Register here: https://jointantra.com/?utm_source=facebook&utm_content=QC3-fb'],
    ]);

    $meta = IdeaMeta::query()->findOrFail($idea->id);
    expect($meta->preview['first_comment'])->toBe('Register here: https://go.usetantra.com/l/ben-first-48-hours-fb-comment')
        ->and(collect($this->made)->last()['channel_id'])->toBe('chn_facebook')
        ->and(collect($this->made)->last()['append_params']['utm_source'])->toBe('facebook')
        ->and(TrackedLink::query()->sole()->piece_ref)->toBe('ri:'.$source->id);
});

it('leaves content alone when the CRM key is not set', function () {
    config(['tantra.crm.api_key' => null]);
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'status' => 'draft', 'platform' => Platform::LinkedIn, 'content' => 'Go: https://jointantra.com/']);

    expect($post->refresh()->content)->toBe('Go: https://jointantra.com/');
    Http::assertNothingSent();
    $this->artisan('tantra:track-links')->expectsOutputToContain('Link tracking is off')->assertSuccessful();
});

it('keeps the old link and reports the error when the CRM refuses', function () {
    config(['tantra.crm.url' => 'https://crm-down.test']);
    Http::fake(['crm-down.test/*' => Http::response(['error' => 'down'], 500)]);
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'status' => 'draft', 'platform' => Platform::LinkedIn, 'content' => 'Go: https://jointantra.com/']);

    expect($post->refresh()->content)->toBe('Go: https://jointantra.com/');
    $this->artisan('tantra:track-links')->expectsOutputToContain('HTTP 500')->assertFailed();
});

it('gives the generator and agents the standing card 29 context', function () {
    $this->getJson('/api/tantra/context', $this->headers)->assertOk()
        ->assertJsonPath('data.links.campaign_id', 'cmp_f427ef8e47514047be1d')
        ->assertJson(fn ($json) => $json->where('data.context', fn (string $c) => str_contains($c, 'Organic traffic')
            && str_contains($c, 'Leading the team @ Tantra') && str_contains($c, 'go.usetantra.com') && ! str_contains($c, "\u{2014}"))->etc());

    $prompt = (new PostWritingAssistant(PostAssistantMode::cases()[0], 'Draft', Locale::cases()[0], Platform::LinkedIn))->instructions();
    expect($prompt)->toContain('https://jointantra.com/')->toContain('bhaskar-funnel-leaks');
});

it('replaces a link before sentence punctuation, and never one inside an email address or subdomain', function () {
    expect(LinkRules::replace('Register at https://jointantra.com/workshop. See you', 'https://jointantra.com/workshop', 'https://go.usetantra.com/l/x'))
        ->toBe('Register at https://go.usetantra.com/l/x. See you')
        ->and(LinkRules::urls('Mail adi@growthclub.org or read blog.jointantra.com/post'))->toBe([])
        ->and(LinkRules::replace('adi@growthclub.org and growthclub.org', 'growthclub.org', 'https://go.usetantra.com/l/x'))
        ->toBe('adi@growthclub.org and https://go.usetantra.com/l/x');

    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'status' => 'draft', 'platform' => Platform::LinkedIn,
        'meta' => ['thread_replies' => [['text' => 'Join us: https://jointantra.com/workshop. Questions? adi@growthclub.org']]]]);

    expect($post->refresh()->meta['thread_replies'][0]['text'])->toBe('Join us: https://go.usetantra.com/l/ben-post-comment. Questions? adi@growthclub.org')
        ->and(count(Storage::disk('local')->get(PieceLinks::LOG) ? explode("\n", trim(Storage::disk('local')->get(PieceLinks::LOG))) : []))->toBe(2);

    // The sweep finds nothing left and logs nothing more.
    $this->artisan('tantra:track-links')->expectsOutputToContain('0 links replaced.')->assertSuccessful();
    expect(count(explode("\n", trim(Storage::disk('local')->get(PieceLinks::LOG)))))->toBe(2);
});

it('gives a link added later its own placement, leaving the first link as it was', function () {
    $post = Post::factory()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->owner->id, 'status' => 'draft', 'platform' => Platform::LinkedIn,
        'media' => [['id' => 'm1', 'type' => 'image', 'url' => 'https://example.test/a.jpg']], 'content' => 'Workshop: https://jointantra.com/']);
    $first = $post->refresh()->content;
    // A post with an image shows no link card, so even the body link is short.
    expect($first)->toBe('Workshop: https://go.usetantra.com/l/ben-workshop-post');

    $post->update(['content' => $first."\nBook: https://crm.usetantra.com/book/tantra/ai-revops-strategy-consultation"]);

    expect($post->refresh()->content)->toBe("Workshop: https://go.usetantra.com/l/ben-workshop-post\nBook: https://go.usetantra.com/l/ben-workshop-post-2")
        ->and(TrackedLink::query()->orderBy('placement')->pluck('destination', 'placement')->all())->toBe([
            'post' => 'https://jointantra.com/',
            'post-2' => 'https://crm.usetantra.com/book/tantra/ai-revops-strategy-consultation',
        ]);
});
