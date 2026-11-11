<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\PostingSchedule;
use App\Tantra\Mcp\Tools\TantraApproveReviewItemTool;
use App\Tantra\Mcp\Tools\TantraEditEngagementCommentTool;
use App\Tantra\Mcp\Tools\TantraGetPostPerformanceTool;
use App\Tantra\Mcp\Tools\TantraGetReviewItemTool;
use App\Tantra\Mcp\Tools\TantraListBoostRunsTool;
use App\Tantra\Mcp\Tools\TantraListReviewItemsTool;
use App\Tantra\Mcp\Tools\TantraRequestChangesTool;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->ws = $result['workspace'];
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);

    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '08:30');
    }
    SocialAccount::factory()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::LinkedIn, 'timezone' => 'America/New_York', 'posting_schedule' => $weekdays]);

    ImportBatch::execute($this->owner, ['engine_batch_id' => '4', 'posts' => [[
        'engine_post_id' => 'A1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-16', 'time' => '08:30',
        'tz' => 'America/New_York', 'text' => 'Post.', 'format' => 'carousel',
        'engagement_comments' => [['by' => 'nelbin', 'text' => 'Useful.', 'reply_to' => null]],
    ]]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'A1')->sole();
});

it('lists, reads, edits and approves review items with the same rules as the review screen', function () {
    TryPostServer::actingAs($this->owner)->tool(TantraListReviewItemsTool::class, ['batch' => '4'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.0.engine_post_id', 'A1')->where('data.0.status', 'in_review')->etc());

    TryPostServer::actingAs($this->owner)->tool(TantraGetReviewItemTool::class, ['review_item_id' => $this->item->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('thread.0.text', 'Useful.')->where('thread.0.index', 0)->etc());

    TryPostServer::actingAs($this->owner)->tool(TantraEditEngagementCommentTool::class, ['review_item_id' => $this->item->id, 'index' => 0, 'text' => "Useful \u{2014} truly."])
        ->assertHasErrors(['No en or em dashes, please.']);
    TryPostServer::actingAs($this->owner)->tool(TantraEditEngagementCommentTool::class, ['review_item_id' => $this->item->id, 'index' => 0, 'text' => 'Useful, thanks.'])
        ->assertOk();
    expect($this->item->refresh()->thread()[0]['text'])->toBe('Useful, thanks.');

    TryPostServer::actingAs($this->owner)->tool(TantraApproveReviewItemTool::class, ['review_item_id' => $this->item->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'scheduled')->etc());
    expect($this->item->refresh()->review_status)->toBe(ReviewStatus::Scheduled);

    TryPostServer::actingAs($this->owner)->tool(TantraGetPostPerformanceTool::class, ['batch' => '4'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('posts.0.key', 'A1')->where('posts.0.impressions', null)->etc());

    TryPostServer::actingAs($this->owner)->tool(TantraListBoostRunsTool::class, [])->assertOk();
});

it('refuses someone who is not the review owner', function () {
    $member = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->ws->members()->attach($member->id, membershipPivot('member'));
    $member->forceFill(['current_workspace_id' => $this->ws->id])->save();

    TryPostServer::actingAs($member)->tool(TantraRequestChangesTool::class, ['review_item_id' => $this->item->id, 'note' => 'Shorter.'])
        ->assertHasErrors(['Only the review owner of this workspace can do that.']);

    expect($this->item->refresh()->review_status)->toBe(ReviewStatus::InReview);
});
