<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Mail\AccountDisconnected;
use App\Mail\PostApprovalRequested;
use App\Mail\PostApproved;
use App\Mail\PostAtRisk;
use App\Mail\PostNoteAdded;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Mail\PostRejected;
use App\Mail\WebhookPausedMail;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Mail\WorkspaceInvite;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Invite;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Tantra\Billing\BillingMail;
use App\Tantra\Billing\WorkspacePlan;
use App\Tantra\Channels\ConnectAccessRequested;
use App\Tantra\Channels\ReconnectChannel;
use App\Tantra\Console\DailyDigestCommand;
use App\Tantra\Digest\DailyDigest;
use App\Tantra\Digest\DigestReport;
use App\Tantra\Mail\MailImage;
use App\Tantra\Manual\ManualTaskDue;
use App\Tantra\Ops\MediaMissing;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Mail\NumbersToSignOff;
use App\Tantra\Review\Mail\ReviewReady;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Team\TeammateWelcome;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

/*
 * tantra: renders every email the Engine sends with realistic data. It checks
 * each one stays under Gmail's clipping limit, and with MAIL_GALLERY_OUT set
 * it writes the HTML and subject of each to that folder for the screenshot
 * matrix (tantra-ops/README "Email gallery").
 */

/** @return array{0: User, 1: Workspace, 2: SocialAccount} */
function galleryWorld(): array
{
    config(['tantra.branding.enabled' => true, 'app.url' => 'https://content.usetantra.com']);
    URL::forceRootUrl('https://content.usetantra.com');

    $user = User::factory()->create(['name' => 'Adi Murali', 'email' => 'adi@example.test']);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id, 'name' => 'Tantra']);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'display_name' => 'Adi Murali',
        'username' => 'adimurali',
    ]);

    return [$user, $workspace, $account];
}

/**
 * Four review items covering every media type, with Tantra's own content
 * files: a single image, a 5 slide carousel, an animated GIF and a video.
 *
 * @return list<string> review item ids in posting order
 */
function galleryReviewItems(User $user, Workspace $workspace): array
{
    $fixtures = base_path('tests/mail-gallery/fixtures');
    $disk = Storage::disk(config('filesystems.default'));
    $put = function (string $file, string $path) use ($disk, $fixtures): string {
        $disk->put($path, (string) file_get_contents("{$fixtures}/{$file}"));

        return $path;
    };
    SocialAccount::query()->where('workspace_id', $workspace->id)->update(['avatar_url' => $put('aquila.jpg', 'avatars/gallery-adi.jpg')]);
    WorkspaceSettings::query()->updateOrCreate(['workspace_id' => $workspace->id], ['brand_profile_key' => 'adi', 'review_owner_user_id' => $user->id]);
    $day = now()->addDays(3)->startOfWeek();

    ImportBatch::execute($user, ['engine_batch_id' => 'G1', 'title' => 'Week of '.$day->format('j M'), 'posts' => [
        ['engine_post_id' => 'G1-1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => $day->toDateString(), 'time' => '09:30', 'tz' => 'Asia/Kolkata',
            'text' => "Most B2B teams post three times a week and wonder why nothing moves.\n\nThe fix is not more posts. It is one idea, said five ways, on the channels your buyers already read.",
            'first_comment' => 'The full routine, with the prompts we use: usetantra.com/routine',
            'media' => ['type' => 'image'], 'preview_paths' => [$put('image.jpg', 'tantra/previews/G1-1/cover.jpg')]],
        ['engine_post_id' => 'G1-2', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => $day->copy()->addDays(1)->toDateString(), 'time' => '08:45', 'tz' => 'Asia/Kolkata',
            'text' => "7 hooks that got us 41 demo calls last quarter.\n\nSwipe through. Number 4 is the one nobody uses and everybody should, because it names the cost of waiting before it names the fix. We tested it against our old opener for six weeks and it won on replies, saves and booked calls, every single week, across three different audiences and two offers.",
            'first_comment' => 'Which one are you trying first? Reply with the number.',
            'media' => ['type' => 'carousel'], 'preview_paths' => array_map(fn (int $n): string => $put(sprintf('p%02d.jpg', $n), sprintf('tantra/previews/G1-2/p%02d.jpg', $n)), range(1, 5))],
        ['engine_post_id' => 'G1-3', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => $day->copy()->addDays(2)->toDateString(), 'time' => '12:00', 'tz' => 'Asia/Kolkata',
            'text' => 'Your content calendar is not the problem. Your distribution is. Here is what one idea looks like after the agents are done with it.',
            'media' => ['type' => 'gif'], 'preview_paths' => [$put('anim.gif', 'tantra/previews/G1-3/anim.gif')]],
        ['engine_post_id' => 'G1-4', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => $day->copy()->addDays(3)->toDateString(), 'time' => '18:00', 'tz' => 'Asia/Kolkata',
            'text' => 'Two minutes on why founder-led content beats paid ads for B2B pipeline in 2026, and the three numbers we track every Friday.',
            'first_comment' => 'Full breakdown in the comments of my last post.',
            'media' => ['type' => 'video'], 'preview_paths' => [$put('video-poster.jpg', 'tantra/previews/G1-4/poster.jpg')]],
    ]]);

    $items = ReviewItem::query()->where('engine_batch_id', 'G1')->orderBy('planned_date')->get();
    $items->firstWhere('engine_post_id', 'G1-2')?->forceFill(['revision' => 2])->save();

    return $items->pluck('id')->all();
}

/** @return array<string, callable(): (Mailable|MailMessage)> */
function galleryEmails(): array
{
    [$user, $workspace, $account] = galleryWorld();
    $itemIds = galleryReviewItems($user, $workspace);
    $batchId = (string) ReviewItem::query()->find($itemIds[0])?->batch_id;
    $user->forceFill(['timezone' => 'Asia/Kolkata'])->save();
    foreach ([[Platform::LinkedIn, 18400, 16900, 'Seven hooks that got us 41 demo calls'], [Platform::Instagram, 6200, 5850, 'Behind the build'], [Platform::X, 3100, 2980, 'Thread: one brief, every draft'], [Platform::YouTube, 2400, 2210, 'The 20 minute routine'], [Platform::Threads, 900, 860, 'Why your calendar is not the problem'], [Platform::Facebook, 640, 600, 'Founder-led content in 2026']] as [$platform, $today, $yesterday, $excerpt]) {
        $publication = AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform, 'network' => $platform->network(), 'excerpt' => $excerpt, 'provider_published_at' => now()->subDays(6)]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => today()->subDay(), 'views_count' => $yesterday]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => today(), 'views_count' => $today]);
    }
    Post::factory()->forAccount($account)->count(3)->create(['status' => PostStatus::Published, 'published_at' => now()->subHours(5)]);
    Post::factory()->forAccount($account)->count(44)->create(['status' => PostStatus::Published, 'published_at' => now()->subDays(20)]);
    Post::factory()->forAccount($account)->count(6)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(3)]);
    $teammate = User::factory()->create(['name' => 'Kavya Rao', 'email' => 'kavya@example.test']);
    $workspace->members()->attach($teammate->id, membershipPivot('member'));
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $user->id,
        'platform_name' => 'Adi Murali',
        'content' => "Most B2B teams post three times a week and wonder why nothing moves.\n\nHere is the 20 minute routine we use to turn one founder insight into a week of posts for every channel.",
        'scheduled_at' => now()->addDays(2)->setTime(9, 30),
    ]);
    $posts = [
        ['id' => 'P-101', 'date' => now()->addDays(1)->toDateString(), 'short' => 'Why your pipeline stalls in week three', 'revised' => false],
        ['id' => 'P-102', 'date' => now()->addDays(2)->toDateString(), 'short' => 'The 20 minute content routine', 'revised' => true],
        ['id' => 'P-103', 'date' => now()->addDays(4)->toDateString(), 'short' => 'Carousel: 7 hooks that got us demos', 'revised' => false],
    ];

    WorkspacePlan::for($workspace->id)->forceFill([
        'status' => 'active', 'dodo_customer_id' => 'cus_gallery', 'billing_email' => $user->email, 'activated_at' => now(),
        'next_billing_at' => now()->addMonth(), 'period_ends_at' => now()->addMonth(), 'payment_failed_at' => now(), 'grace_ends_at' => now()->addDays(10),
    ])->save();
    $billing = fn (string $kind, array $context = []) => fn () => new BillingMail($kind, $workspace->id, $context + ['amount' => '$199.00']);

    return [
        'billing-welcome' => $billing('welcome'),
        'billing-receipt' => $billing('receipt'),
        'billing-renewal-notice' => $billing('renewal_notice'),
        'billing-payment-failed-day-0' => $billing('payment_failed', ['step' => 0]),
        'billing-payment-failed-day-3' => $billing('payment_failed', ['step' => 1]),
        'billing-payment-failed-day-7' => $billing('payment_failed', ['step' => 2]),
        'billing-paused' => $billing('paused'),
        'billing-cancelled' => $billing('cancelled'),
        'billing-expired' => $billing('expired'),
        'billing-winback' => $billing('winback'),
        'review-ready' => fn () => new ReviewReady($batchId, 'Tantra', '16 Nov', array_fill(0, 4, $posts[0]), $itemIds, 'Asia/Kolkata'),
        'daily-digest' => fn () => new DailyDigest('Adi', DailyDigestCommand::stats(DigestReport::for($user), CarbonImmutable::now('Asia/Kolkata')), URL::signedRoute('tantra.digest.unsubscribe', ['user' => $user->id]), 'Asia/Kolkata'),
        'numbers-to-sign-off' => fn () => new NumbersToSignOff('01a1d8ba-0000-7000-8000-000000000001', 'Tantra', [
            ['id' => 'P-101', 'date' => now()->addDay()->toDateString(), 'short' => 'Why your pipeline stalls', 'numbers' => ['$2.4M pipeline from LinkedIn in 2025', '41% reply rate on the first DM']],
        ]),
        'manual-task-due' => fn () => new ManualTaskDue('task-1', 'linkedin_poll', 'P-104', 'Which channel brought you the most demos this quarter?'),
        'media-missing' => fn () => new MediaMissing('P-105', 'Tantra', now()->addHours(5)->toIso8601String(), 'Carousel: 7 hooks that got us demos'),
        'reconnect-channel' => fn () => new ReconnectChannel('Tantra', 'Adi Murali on LinkedIn', 'expiring', 5),
        'connect-access-requested' => fn () => new ConnectAccessRequested('Kavya Rao', 'kavya@example.test', 'Tantra', 'Instagram', '@tantra.ai', 'Add Kavya as a tester in the Meta app, then approve.', false, 'https://content.usetantra.com/settings/channels', 'https://developers.facebook.com/apps'),
        'teammate-welcome' => fn () => new TeammateWelcome('Kavya', 'Tantra', 'https://content.usetantra.com/join/abc123'),
        'account-disconnected' => fn () => new AccountDisconnected($account),
        'workspace-connections-disconnected' => fn () => new WorkspaceConnectionsDisconnected($workspace, collect([$account])),
        'post-at-risk' => fn () => new PostAtRisk($workspace, [$post->id], 1, $user),
        'post-note-added' => fn () => new PostNoteAdded(PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $teammate->id, 'body' => 'Can we open with the pipeline number instead?']), $teammate),
        'post-approval-requested' => fn () => new PostApprovalRequested($post->id, $teammate, $user),
        'post-approved' => fn () => new PostApproved($post->id, $user, $teammate),
        'post-rejected' => fn () => new PostRejected($post->id, $user),
        'post-published' => fn () => new PostPublished($post),
        'post-publish-failed' => fn () => new PostPublishFailed($post),
        'webhook-paused' => fn () => new WebhookPausedMail(Webhook::factory()->create(['workspace_id' => $workspace->id, 'endpoint' => 'https://hooks.example.test/tantra'])),
        'workspace-invite' => fn () => new WorkspaceInvite(Invite::factory()->create(['account_id' => $user->account_id, 'email' => 'new.hire@example.test', 'invited_by' => $user->id, 'workspaces' => [$workspace->id]])),
        'email-verification' => fn () => (new VerifyEmail)->toMail($user),
        'password-reset' => fn () => (new ResetPassword('token123'))->toMail($user),
    ];
}

function galleryRender(Mailable|MailMessage $mail): array
{
    if ($mail instanceof MailMessage) {
        return [(string) $mail->subject, (string) $mail->render()];
    }

    return [(string) ($mail->envelope()->subject ?? $mail->subject), (string) $mail->render()];
}

/** The email-sized images an email links, rendered here and written out so the screenshots show them. */
function galleryMedia(string $html, string $out): array
{
    $map = [];
    preg_match_all('#https?://[^"\'\s)]+/tantra/mail-image/(cover|thumb|gif)/([A-Za-z0-9_-]+)[^"\'\s)]*#', html_entity_decode($html), $found, PREG_SET_ORDER);
    foreach ($found as [$url, $kind, $src]) {
        $file = MailImage::render(MailImage::decode($src), $kind);
        if ($file !== null) {
            $name = 'media/'.sha1($url).($kind === 'gif' ? '.gif' : '.jpg');
            @mkdir("{$out}/media", 0777, true);
            file_put_contents("{$out}/{$name}", $file[0]);
            $map[$url] = $name;
        }
    }

    return $map;
}

test('every email renders, stays under the Gmail clipping limit and can be written out for the gallery', function () {
    Storage::fake(config('filesystems.default'));
    $out = env('MAIL_GALLERY_OUT');
    $index = [];
    $media = [];

    foreach (galleryEmails() as $name => $make) {
        [$subject, $html] = galleryRender($make());

        $text = html_entity_decode(strip_tags(preg_replace('#<(style|title)[^>]*>.*?</\1>#s', '', $html) ?? ''));

        expect(strlen($html))->toBeLessThan(102 * 1024, "{$name} is over Gmail's clipping limit")
            ->and(str_contains($subject.$text, "\u{2014}"))->toBeFalse("{$name} has an em dash")
            ->and(str_contains($subject.$text, "\u{2013}"))->toBeFalse("{$name} has an en dash")
            ->and(str_contains($text, 'TryPost'))->toBeFalse("{$name} still says TryPost")
            ->and((bool) preg_match('/\{"[a-z_]+":/', $text))->toBeFalse("{$name} prints a model as JSON")
            ->and(str_contains($html, 'tantra/email/logo.png'))->toBeTrue("{$name} is not on the shared layout");

        if ($out) {
            @mkdir($out, 0777, true);
            file_put_contents("{$out}/{$name}.html", $html);
            $index[$name] = ['subject' => $subject, 'bytes' => strlen($html)];
            $media += galleryMedia($html, $out);
        }
    }

    if ($out) {
        file_put_contents("{$out}/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents("{$out}/media.json", json_encode($media, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
});
