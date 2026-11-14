<?php

declare(strict_types=1);

namespace App\Tantra\Team;

use App\Tantra\Mail\Kit;
use App\Tantra\Mail\Letter;
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

        return Letter::make("{$first}, your next LinkedIn posts are written")
            ->hero(Kit::asset('tantra/email/content-os-hero.gif'), 'One idea goes in, agents turn it into posts for every channel, and views and pipeline come back')
            ->eyebrow("Welcome to {$this->workspaceName}")
            ->lead('Your posts are drafted from your own ideas and ready for you in the Tantra Content Engine. Nothing goes out until you approve it, and approved posts go out from your own profile at their planned time.')
            ->steps(['Set your password.', 'Connect your LinkedIn under Channels.', 'Approve each post or ask for changes.'])
            ->cta('Set your password', $this->link)
            ->note('This link works for 30 days. If it has run out, ask your workspace owner for a new one.')
            ->content();
    }
}
