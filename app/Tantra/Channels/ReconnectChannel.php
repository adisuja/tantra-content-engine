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

/** tantra: "Your LinkedIn connection runs out in 7 days", to the workspace's review owner. */
class ReconnectChannel extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $workspaceName,
        public string $channelName,
        public string $state,
        public ?int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->state === 'dropped'
            ? 'Reconnect LinkedIn so your posts can go out'
            : 'Your LinkedIn connection runs out '.$this->dueText());
    }

    public function content(): Content
    {
        $dropped = $this->state === 'dropped';

        return Letter::make($dropped ? 'LinkedIn stopped posting for '.$this->channelName : 'Reconnect LinkedIn '.$this->dueText().' to keep posting')
            ->eyebrow($dropped ? 'Posting is paused' : 'Action needed soon', $dropped ? 'bad' : 'warn')
            ->lead($dropped
                ? "The LinkedIn connection for {$this->channelName} in {$this->workspaceName} stopped working. Scheduled posts there will not go out until you connect it again."
                : "LinkedIn asks everyone to sign in again every 60 days. The connection for {$this->channelName} in {$this->workspaceName} runs out {$this->dueText()}. Reconnect now and every scheduled post goes out as planned.")
            ->para('It takes about a minute: sign in to LinkedIn and approve the access again.')
            ->cta('Reconnect LinkedIn', url('/tantra/review'))
            ->reason("You get this because you review posts for {$this->workspaceName}.")
            ->content();
    }

    private function dueText(): string
    {
        return match (true) {
            $this->daysLeft === null, $this->daysLeft <= 0 => 'today',
            $this->daysLeft === 1 => 'tomorrow',
            default => "in {$this->daysLeft} days",
        };
    }
}
