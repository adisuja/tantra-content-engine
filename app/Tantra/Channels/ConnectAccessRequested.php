<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Tantra\Mail\Letter;
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
        return new Envelope(subject: "{$this->requesterName} is waiting on you to connect {$this->network}");
    }

    public function content(): Content
    {
        $where = $this->workspaceName !== '' ? " in {$this->workspaceName}" : '';
        $letter = Letter::make("{$this->requesterName} is waiting to connect {$this->network}")
            ->eyebrow('Your step', 'warn')
            ->lead($this->ready
                ? "{$this->requesterName} tried to connect {$this->network}{$where}. The {$this->network} app is still in testing, so only people you add as testers can sign in."
                : "{$this->requesterName} tried to connect {$this->network}{$where}, but the {$this->network} app keys are not in yet, so nobody can connect it.")
            ->facts([['Who', "{$this->requesterName} ({$this->requesterEmail})"], ['Username', $this->handle], ['Network', $this->network]]);

        if ($this->ready) {
            $letter->quote($this->ownerSteps, 'What to do')
                ->para('Once you add them, they accept the invite and press Continue. Nothing else is needed from you.')
                ->cta('Open the developer dashboard', $this->consoleUrl !== '' ? $this->consoleUrl : $this->settingsUrl);
        } else {
            $letter->para('Add the keys in Settings, Connectors, Channels. Then tell them they can connect.')
                ->cta('Open Channels settings', $this->settingsUrl);
        }

        return $letter->reason('You get this because you manage the channel apps for this account.')->content();
    }
}
