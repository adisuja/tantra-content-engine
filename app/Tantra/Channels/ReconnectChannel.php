<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

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
        $intro = $this->state === 'dropped'
            ? "The LinkedIn connection for {$this->channelName} in {$this->workspaceName} has stopped working. Scheduled posts there will not go out until you connect it again. It takes about a minute."
            : "The LinkedIn connection for {$this->channelName} in {$this->workspaceName} runs out {$this->dueText()}. LinkedIn asks everyone to sign in again every 60 days. Reconnect now and your scheduled posts carry on as planned.";

        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => $this->state === 'dropped' ? 'Your LinkedIn needs reconnecting' : 'Time to reconnect LinkedIn',
            'intro' => $intro,
            'posts' => [],
            'url' => url('/tantra/review'),
            'button' => 'Reconnect LinkedIn',
        ]);
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
