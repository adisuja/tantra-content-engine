<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * tantra: "Maya wants to connect Threads", to the person who looks after the
 * developer apps, from "Request access" on the pre-connect step. Sent through
 * Notify as `engine.connect_access_requested`; this is its email.
 */
class ConnectAccessRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $requesterName,
        public string $requesterEmail,
        public string $workspaceName,
        public string $network,
        public string $handle,
        public string $ownerSteps,
        public bool $ready,
        public string $settingsUrl,
        public string $consoleUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->requesterName} wants to connect {$this->network}");
    }

    public function content(): Content
    {
        $who = $this->handle !== '' ? "{$this->requesterName} ({$this->requesterEmail}, {$this->network} username {$this->handle})" : "{$this->requesterName} ({$this->requesterEmail})";
        $where = $this->workspaceName !== '' ? " in {$this->workspaceName}" : '';

        $intro = $this->ready
            ? "{$who} tried to connect {$this->network}{$where}, but the {$this->network} app is still in testing, so only testers can sign in. The step we showed {$this->requesterName} says: \"{$this->ownerSteps}\" Once you have added them, they accept the invite and press Continue."
            : "{$who} tried to connect {$this->network}{$where}, but its app keys are not in yet. Add them in Settings, Connectors, Channels, then let them know they can connect.";

        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => "{$this->requesterName} wants to connect {$this->network}",
            'intro' => $intro,
            'posts' => [],
            'url' => $this->ready && $this->consoleUrl !== '' ? $this->consoleUrl : $this->settingsUrl,
            'button' => $this->ready ? 'Open the developer dashboard' : 'Open Channels settings',
        ]);
    }
}
