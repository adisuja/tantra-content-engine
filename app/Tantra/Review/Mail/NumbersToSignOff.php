<?php

declare(strict_types=1);

namespace App\Tantra\Review\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** tantra: Adi's numbers quoted in a teammate's posts wait for his sign off before they can be approved (PRD 10.2). */
class NumbersToSignOff extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<array{id: string, date: string|null, short: string|null, numbers: list<string>}> $posts */
    public function __construct(
        public string $batchId,
        public string $workspaceName,
        public array $posts,
    ) {}

    public function envelope(): Envelope
    {
        $n = array_sum(array_map(fn (array $p): int => count($p['numbers']), $this->posts));

        return new Envelope(subject: ($n === 1 ? '1 number' : "{$n} numbers")." to sign off in {$this->workspaceName}'s posts");
    }

    public function content(): Content
    {
        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => 'Your numbers need your sign off',
            'intro' => "These posts in {$this->workspaceName} quote numbers that are yours. They cannot be approved until you sign each one off.",
            'posts' => $this->posts,
            'url' => url('/tantra/review/'.$this->batchId),
            'button' => 'Sign them off',
        ]);
    }
}
