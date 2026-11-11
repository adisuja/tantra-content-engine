<?php

declare(strict_types=1);

namespace App\Tantra\Team;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** tantra: the welcome a teammate gets with their set-password link. */
class TeammateWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $workspaceName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your LinkedIn posts are ready for you to review');
    }

    public function content(): Content
    {
        $first = strtok($this->name, ' ') ?: $this->name;

        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => "Hi {$first}, your posts are waiting",
            'intro' => 'Your next LinkedIn posts are written and ready in the Tantra Content Engine. Set your password, connect your LinkedIn under Channels, then approve each post or ask for changes. Approved posts go out at their planned time from your own profile.',
            'posts' => [],
            'url' => $this->link,
            'button' => 'Set your password',
        ]);
    }
}
