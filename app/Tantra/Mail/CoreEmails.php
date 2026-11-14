<?php

declare(strict_types=1);

namespace App\Tantra\Mail;

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
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Mail\ApprovalEmailPost;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Str;

/**
 * tantra: Tantra's subject and body for upstream's emails (app/Mail), on the
 * shared layout. Each upstream Mailable asks here first and keeps its own
 * view when Tantra branding is off, which is how upstream's tests run.
 */
final class CoreEmails
{
    public static function enabled(): bool
    {
        return (bool) config('tantra.branding.enabled');
    }

    public static function subject(Mailable $mail): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        return match (true) {
            $mail instanceof AccountDisconnected => 'Posting to '.$mail->account->platform->label().' has stopped: reconnect '.$mail->account->accountDisplayName(),
            $mail instanceof WorkspaceConnectionsDisconnected => self::count($mail->disconnectedAccounts->count(), 'channel').' in '.$mail->workspace->name.(($mail->disconnectedAccounts->count() === 1) ? ' needs' : ' need').' reconnecting',
            $mail instanceof PostAtRisk => self::count($mail->count, 'scheduled post').' in '.$mail->workspace->name.' will not go out unless you reconnect',
            $mail instanceof PostNoteAdded => $mail->author->name.' left a note on your post',
            $mail instanceof PostApprovalRequested => $mail->requester->name.' needs your approval on a post',
            $mail instanceof PostApproved => 'Approved: your post is scheduled',
            $mail instanceof PostRejected => $mail->approver->name.' sent your post back with changes',
            $mail instanceof PostPublished => 'Live on '.Kit::networkLabel($mail->post->platform).': your post just went out',
            $mail instanceof PostPublishFailed => 'Your '.Kit::networkLabel($mail->post->platform).' post did not go out',
            $mail instanceof WebhookPausedMail => 'Webhook paused after 5 failed deliveries',
            $mail instanceof WorkspaceInvite => ($mail->invite->invitedBy?->name ?? 'Your team').' invited you to '.self::inviteWorkspace($mail),
            default => null,
        };
    }

    public static function content(Mailable $mail): ?Content
    {
        if (! self::enabled()) {
            return null;
        }

        return match (true) {
            $mail instanceof AccountDisconnected => self::accountDisconnected($mail->account)->content(),
            $mail instanceof WorkspaceConnectionsDisconnected => self::connectionsDisconnected($mail->workspace, $mail->disconnectedAccounts->count())->content(),
            $mail instanceof PostAtRisk => self::postAtRisk($mail)->content(),
            $mail instanceof PostNoteAdded => self::noteAdded($mail)->content(),
            $mail instanceof PostApprovalRequested => self::approvalRequested($mail)->content(),
            $mail instanceof PostApproved => self::approved($mail)->content(),
            $mail instanceof PostRejected => self::rejected($mail)->content(),
            $mail instanceof PostPublished => self::published($mail->post)->content(),
            $mail instanceof PostPublishFailed => self::failed($mail->post)->content(),
            $mail instanceof WebhookPausedMail => Letter::make('A webhook stopped receiving events')
                ->eyebrow('Integration paused', 'warn')
                ->lead('Five deliveries in a row to this endpoint failed, so the Engine paused it. Anything that relies on it, such as a CRM or Slack sync, is not getting post events.')
                ->facts([['Endpoint', $mail->webhook->endpoint]])
                ->para('Check the endpoint is up, then turn the webhook back on. Events from the pause are not sent again.')
                ->cta('Open the webhook', route('app.webhooks.show', $mail->webhook))
                ->reason('You get this because you own this webhook.')
                ->content(),
            $mail instanceof WorkspaceInvite => self::invite($mail)->content(),
            default => null,
        };
    }

    public static function verifyEmail(string $url): Letter
    {
        return Letter::make('Confirm your email')
            ->eyebrow('One step left')
            ->lead('Confirm this is your address so we can send you review requests and publishing alerts.')
            ->cta('Confirm my email', $url)
            ->note('If you did not create a Tantra account, ignore this email.');
    }

    public static function resetPassword(string $url): Letter
    {
        return Letter::make('Reset your password')
            ->eyebrow('Password reset')
            ->lead('Someone asked to reset the password for your Tantra account. If it was you, choose a new one below.')
            ->cta('Choose a new password', $url)
            ->note('The link works for 60 minutes. If you did not ask for this, ignore this email: your password stays the same.');
    }

    private static function accountDisconnected(SocialAccount $account): Letter
    {
        $network = $account->platform->label();

        return Letter::make("{$network} stopped posting for ".$account->accountDisplayName())
            ->eyebrow('Posting is paused', 'bad')
            ->lead("The {$network} connection in {$account->workspace->name} stopped working, usually because the sign in expired or a password changed. Scheduled posts for this channel will not go out until you connect it again.")
            ->facts([['Channel', $account->accountDisplayName()], ['Network', $network], ['Workspace', $account->workspace->name]])
            ->para('Reconnecting takes about a minute and keeps every scheduled post in its slot.')
            ->cta("Reconnect {$network}", route('app.workspace.channels'))
            ->reason('You get this because you own this workspace.');
    }

    private static function connectionsDisconnected(Workspace $workspace, int $count): Letter
    {
        return Letter::make(self::count($count, 'channel').' stopped posting in '.$workspace->name)
            ->eyebrow('Posting is paused', 'bad')
            ->lead('Our daily check found '.($count === 1 ? 'a channel whose' : 'channels whose').' sign in no longer works. Posts scheduled there will not go out until '.($count === 1 ? 'it is' : 'they are').' reconnected.')
            ->cta('Reconnect channels', route('app.workspace.channels'))
            ->reason('You get this because you own this workspace.');
    }

    private static function postAtRisk(PostAtRisk $mail): Letter
    {
        $posts = Post::query()->with('socialAccount')->whereIn('id', $mail->postIds)->orderBy('scheduled_at')->get();
        $next = $posts->first();
        $letter = Letter::make(self::count($mail->count, 'scheduled post').' will not go out')
            ->eyebrow('Fix before the slot', 'warn')
            ->lead('The channel '.($mail->count === 1 ? 'this post goes' : 'these posts go').' to is disconnected. Reconnect it before the slot and nothing changes. If not, '.($mail->count === 1 ? 'it fails' : 'they fail').' at the posting time.');
        if ($next !== null) {
            $letter->card(PostCard::fromPost($next, $mail->workspace->owner?->timezone, route('app.posts.edit', $next), 'Open the post'));
        }

        return $letter->cta('Reconnect the channel', route('app.workspace.channels'))
            ->reason('You get this because you own '.$mail->workspace->name.'.');
    }

    private static function noteAdded(PostNoteAdded $mail): Letter
    {
        $post = $mail->note->post;
        $url = route('app.posts.edit', ['post' => $post, 'comment' => $mail->note->id]);

        return Letter::make($mail->author->name.' left a note on your post')
            ->eyebrow('New note')
            ->lead('Read it, reply in the post, or change the copy before it goes out.')
            ->quote((string) $mail->note->body, $mail->author->name)
            ->card($post->platform !== null ? PostCard::fromPost($post, null, $url, 'Open the post') : null)
            ->cta('Reply to '.Str::of($mail->author->name)->before(' '), $url)
            ->reason('You get this because you are in '.$post->workspace->name.'.');
    }

    private static function approvalRequested(PostApprovalRequested $mail): Letter
    {
        $post = ApprovalEmailPost::find($mail->postId);
        $when = ApprovalEmailPost::time($post?->scheduled_at, $mail->recipient);

        return Letter::make($mail->requester->name.' needs your approval')
            ->eyebrow('Approval needed')
            ->lead('This post waits for you. Approve it and it goes out '.($when ? "on {$when}" : 'in its slot').', or send it back with a note.')
            ->card($post && $post->platform !== null ? PostCard::fromPost($post, $mail->recipient->timezone, route('app.posts.index', ['tab' => 'approvals']), 'Review the post') : null)
            ->cta('Review and approve', route('app.posts.index', ['tab' => 'approvals']))
            ->reason('You get this because you approve posts in '.($post?->workspace?->name ?? 'this workspace').'.');
    }

    private static function approved(PostApproved $mail): Letter
    {
        $post = ApprovalEmailPost::find($mail->postId);
        $when = ApprovalEmailPost::goesOutAt($post, $mail->recipient);

        return Letter::make('Your post is approved')
            ->eyebrow('Approved', 'good')
            ->lead($mail->approver->name.' approved it. '.($when ? "It goes out on {$when}." : 'It goes out in its slot.').' Nothing else to do.')
            ->card($post && $post->platform !== null ? PostCard::fromPost($post, $mail->recipient->timezone, route('app.posts.index', ['tab' => 'queue']), 'See it in the queue') : null)
            ->cta('Open the queue', route('app.posts.index', ['tab' => 'queue']));
    }

    private static function rejected(PostRejected $mail): Letter
    {
        $post = ApprovalEmailPost::find($mail->postId);

        return Letter::make($mail->approver->name.' sent your post back')
            ->eyebrow('Changes needed', 'warn')
            ->lead('It is back in your drafts. Read the notes on the post, change it and send it for approval again.')
            ->card($post && $post->platform !== null ? PostCard::fromPost($post, null, route('app.posts.index', ['tab' => 'drafts']), 'Open the draft') : null)
            ->cta('Edit the post', route('app.posts.index', ['tab' => 'drafts']));
    }

    private static function published(Post $post): Letter
    {
        $post->loadMissing('socialAccount', 'workspace');
        $network = Kit::networkLabel($post->platform);
        $letter = Letter::make("Your post is live on {$network}")
            ->eyebrow('Published', 'good')
            ->lead('The first hour decides how far a post travels. Reply to the first comments while people are still reading.')
            ->card(PostCard::fromPost($post, $post->user?->timezone, $post->platform_url ?: route('app.posts.edit', $post), $post->platform_url ? "See it on {$network}" : 'Open the post'));
        if ($post->platform_url) {
            $letter->cta("Open on {$network}", (string) $post->platform_url)->secondary('Open in Tantra', route('app.posts.edit', $post));
        } else {
            $letter->cta('Open in Tantra', route('app.posts.edit', $post));
        }

        return $letter->reason('You get this because you are in '.$post->workspace->name.'. You can turn these off in email settings.');
    }

    private static function failed(Post $post): Letter
    {
        $post->loadMissing('socialAccount', 'workspace');
        $network = Kit::networkLabel($post->platform);

        return Letter::make("Your {$network} post did not go out")
            ->eyebrow('Publishing failed', 'bad')
            ->lead("{$network} refused it at the posting time, so it is not live. Fix the cause below and publish it again; the copy and media are kept.")
            ->facts([['Reason', (string) ($post->error_message ?: 'The network did not say why.')], ['Channel', (string) ($post->display_name ?: $network)]])
            ->card(PostCard::fromPost($post, $post->user?->timezone, route('app.posts.index', ['tab' => 'sent', 'post' => $post->id]), 'Open the post'))
            ->cta('Fix and publish again', route('app.posts.index', ['tab' => 'sent', 'post' => $post->id]))
            ->reason('You get this because you are in '.$post->workspace->name.'.');
    }

    private static function invite(WorkspaceInvite $mail): Letter
    {
        $workspace = self::inviteWorkspace($mail);

        return Letter::make("Join the {$workspace} workspace")
            ->eyebrow('You are invited')
            ->lead("You have been invited to {$workspace} in the Tantra Content Engine, where the team turns ideas into posts for every channel and reviews them before they go out.")
            ->facts([['Role', $mail->invite->is_admin ? 'Admin' : 'Member'], ['Your posts', $mail->invite->requires_approval ? 'Go out after an admin approves them' : 'Go out when you schedule them']])
            ->cta('Accept the invite', route('app.invites.show', $mail->invite))
            ->note('If you were not expecting this, ignore the email and nothing happens.');
    }

    private static function inviteWorkspace(WorkspaceInvite $mail): string
    {
        $ids = (array) ($mail->invite->workspaces ?? []);

        return (string) (Workspace::query()->whereIn('id', $ids)->value('name') ?? 'a workspace');
    }

    private static function count(int $n, string $noun): string
    {
        return $n === 1 ? "1 {$noun}" : "{$n} {$noun}s";
    }
}
