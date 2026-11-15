<?php

declare(strict_types=1);

namespace App\Tantra\Notify;

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
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\ApprovalEmailPost;
use App\Tantra\Mail\CoreEmails;
use App\Tantra\Mail\Kit;
use App\Tantra\Mail\PostCard;
use Illuminate\Mail\Mailable;
use Throwable;

/**
 * tantra: the 11 upstream emails the Engine mails straight from its own mailer (app/Mail), as engine.* events with the
 * data the main app needs to render the same email on its kit (tantra-crm src/mail/catalog.ts, R3 15 Nov). Each event
 * carries data.for_mail = true, so the main app can tell a member's email event from other events with the same name
 * (engine.post_published is also sent once per post for Engagement, without it).
 */
final class CoreEvents
{
    /** Every event this Engine can send for an upstream email, and the fields it adds to three Notify events. Read by the main app (cutover plan). */
    public const array EMITS = [
        'engine.account_disconnected', 'engine.workspace_connections_disconnected', 'engine.post_at_risk', 'engine.post_note_added',
        'engine.post_approval_requested', 'engine.post_approved', 'engine.post_rejected', 'engine.post_published',
        'engine.post_publish_failed', 'engine.webhook_paused', 'engine.workspace_invite',
        'engine.numbers_to_sign_off:quotes', 'engine.manual_task_due:text,network', 'engine.media_missing:excerpt,due_label',
        'engine.review_ready:posts', 'engine.daily_digest:posts',
    ];

    /**
     * EMITS as one comma separated header. The header itself is split on commas, so an event with several fields is
     * sent as one token per field ("engine.manual_task_due:text,engine.manual_task_due:network"); the main app merges
     * them back per event. Joining EMITS as is dropped every field after the first (O-13).
     */
    public static function emitsHeader(): string
    {
        $tokens = [];
        foreach (self::EMITS as $emit) {
            [$event, $fields] = array_pad(explode(':', $emit, 2), 2, '');
            if ($fields === '') {
                $tokens[] = $event;

                continue;
            }
            foreach (explode(',', $fields) as $field) {
                $tokens[] = $event.':'.$field;
            }
        }

        return implode(',', $tokens);
    }

    /** The engine.* event name for an upstream Mailable, or null when it is not one of the 11. */
    public static function eventFor(Mailable $mail): ?string
    {
        return match (true) {
            $mail instanceof AccountDisconnected => 'engine.account_disconnected',
            $mail instanceof WorkspaceConnectionsDisconnected => 'engine.workspace_connections_disconnected',
            $mail instanceof PostAtRisk => 'engine.post_at_risk',
            $mail instanceof PostNoteAdded => 'engine.post_note_added',
            $mail instanceof PostApprovalRequested => 'engine.post_approval_requested',
            $mail instanceof PostApproved => 'engine.post_approved',
            $mail instanceof PostRejected => 'engine.post_rejected',
            $mail instanceof PostPublished => 'engine.post_published',
            $mail instanceof PostPublishFailed => 'engine.post_publish_failed',
            $mail instanceof WebhookPausedMail => 'engine.webhook_paused',
            $mail instanceof WorkspaceInvite => 'engine.workspace_invite',
            default => null,
        };
    }

    /**
     * The event's data: the same facts the Engine's own email shows (names, network, the post card, the link).
     *
     * @return array<string, mixed>
     */
    public static function dataFor(Mailable $mail, ?User $to): array
    {
        $data = match (true) {
            $mail instanceof AccountDisconnected => [
                'channel' => $mail->account->accountDisplayName(), 'network' => Kit::networkOf($mail->account->platform),
                'workspace' => $mail->account->workspace?->name, 'url' => route('app.workspace.channels'),
            ],
            $mail instanceof WorkspaceConnectionsDisconnected => [
                'count' => $mail->disconnectedAccounts->count(), 'workspace' => $mail->workspace->name,
                'channels' => $mail->disconnectedAccounts->map(fn ($a): string => (string) $a->accountDisplayName())->take(10)->values()->all(),
                'url' => route('app.workspace.channels'),
            ],
            $mail instanceof PostAtRisk => self::atRisk($mail, $to),
            $mail instanceof PostNoteAdded => [
                'by' => $mail->author->name, 'note' => mb_substr((string) $mail->note->body, 0, 2000), 'workspace' => $mail->note->post?->workspace?->name,
                'posts' => self::cards($mail->note->post, $to, route('app.posts.edit', ['post' => $mail->note->post, 'comment' => $mail->note->id])),
                'url' => route('app.posts.edit', ['post' => $mail->note->post, 'comment' => $mail->note->id]),
            ],
            $mail instanceof PostApprovalRequested => self::approval($mail->postId, $mail->requester->name, $to, 'approvals', ApprovalEmailPost::time(ApprovalEmailPost::find($mail->postId)?->scheduled_at, $mail->recipient)),
            $mail instanceof PostApproved => self::approval($mail->postId, $mail->approver->name, $to, 'queue', ApprovalEmailPost::goesOutAt(ApprovalEmailPost::find($mail->postId), $mail->recipient)),
            $mail instanceof PostRejected => self::approval($mail->postId, $mail->approver->name, $to, 'drafts', null),
            $mail instanceof PostPublished => self::post($mail->post, $to, true),
            $mail instanceof PostPublishFailed => self::post($mail->post, $to, false),
            $mail instanceof WebhookPausedMail => ['endpoint' => $mail->webhook->endpoint, 'workspace' => $mail->webhook->workspace?->name, 'url' => route('app.webhooks.show', $mail->webhook)],
            $mail instanceof WorkspaceInvite => [
                'by' => $mail->invite->invitedBy?->name, 'workspace' => (string) (Workspace::query()->whereIn('id', (array) ($mail->invite->workspaces ?? []))->value('name') ?? 'a workspace'),
                'admin' => (bool) $mail->invite->is_admin, 'requires_approval' => (bool) $mail->invite->requires_approval, 'url' => route('app.invites.show', $mail->invite),
            ],
            default => [],
        };

        try {
            $subject = CoreEmails::subject($mail);
        } catch (Throwable) {
            $subject = null;
        }

        return array_filter($data, fn (mixed $v): bool => $v !== null) + ['for_mail' => true] + ($subject !== null ? ['subject' => $subject] : []);
    }

    /** @return list<array<string, mixed>> */
    private static function cards(?Post $post, ?User $to, string $url, string $label = 'Open the post'): array
    {
        if ($post === null || $post->platform === null) {
            return [];
        }
        try {
            return [PostCard::fromPost($post, $to?->timezone, $url, $label)];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private static function approval(string $postId, string $by, ?User $to, string $tab, ?string $when): array
    {
        $post = ApprovalEmailPost::find($postId);
        $url = route('app.posts.index', ['tab' => $tab]);

        return ['by' => $by, 'when' => $when, 'post_id' => $postId, 'workspace' => $post?->workspace?->name, 'posts' => self::cards($post, $to, $url), 'url' => $url];
    }

    /** @return array<string, mixed> */
    private static function post(Post $post, ?User $to, bool $live): array
    {
        $post->loadMissing('socialAccount', 'workspace');
        $url = $live ? route('app.posts.edit', $post) : route('app.posts.index', ['tab' => 'sent', 'post' => $post->id]);

        return [
            'post_id' => (string) $post->id, 'network' => Kit::networkOf($post->platform), 'channel' => $post->display_name,
            'workspace' => $post->workspace?->name, 'permalink' => $live ? $post->platform_url : null,
            'reason' => $live ? null : ($post->error_message ?: null),
            'posts' => self::cards($post, $to, $live ? ($post->platform_url ?: $url) : $url), 'url' => $url,
        ];
    }

    /** @return array<string, mixed> */
    private static function atRisk(PostAtRisk $mail, ?User $to): array
    {
        $next = Post::query()->with('socialAccount')->whereIn('id', $mail->postIds)->orderBy('scheduled_at')->first();

        return [
            'count' => $mail->count, 'workspace' => $mail->workspace->name, 'post_ids' => array_slice(array_values($mail->postIds), 0, 20),
            'posts' => $next ? self::cards($next, $to ?? $mail->workspace->owner, route('app.posts.edit', $next)) : [], 'url' => route('app.workspace.channels'),
        ];
    }
}
