<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\ApprovalEmailPost;
use App\Tantra\Mail\CoreEmails;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $postId,
        public User $approver,
    ) {}

    public function envelope(): Envelope
    {
        // tantra: Tantra's subject when branding is on (App\Tantra\Mail\CoreEmails).
        if (($tantra = CoreEmails::subject($this)) !== null) {
            return new Envelope(subject: $tantra);
        }

        return new Envelope(
            subject: __('mail.post_rejected.subject', ['name' => $this->approver->name]),
        );
    }

    public function content(): Content
    {
        // tantra: the shared Tantra email layout when branding is on.
        if (($tantra = CoreEmails::content($this)) !== null) {
            return $tantra;
        }

        $post = ApprovalEmailPost::find($this->postId);
        $workspaceName = (string) $post?->workspace?->name;

        return new Content(
            view: 'mail.post-rejected',
            with: [
                'title' => __('mail.post_rejected.title'),
                'previewText' => __('mail.post_rejected.preview', ['name' => $this->approver->name]),
                'approverName' => $this->approver->name,
                'workspaceName' => $workspaceName,
                'channel' => ApprovalEmailPost::channel($post),
                'url' => route('app.posts.index', ['tab' => 'drafts']),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
