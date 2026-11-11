<?php

declare(strict_types=1);

namespace App\Tantra\Review\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** tantra: "5 new posts to review for the week of 16 Nov", to a workspace's review owner after an import (PRD 10.1). */
class ReviewReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<array{id: string, date: string|null, short: string|null, revised: bool}> $posts */
    public function __construct(
        public string $batchId,
        public string $workspaceName,
        public string $weekLabel,
        public array $posts,
    ) {}

    public function envelope(): Envelope
    {
        $n = count($this->posts);
        $what = $n === 1 ? '1 new post' : "{$n} new posts";

        return new Envelope(subject: "{$what} to review for the week of {$this->weekLabel}");
    }

    public function content(): Content
    {
        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => count($this->posts) === 1 ? 'A post is ready for your review' : count($this->posts).' posts are ready for your review',
            'intro' => "In {$this->workspaceName}, for the week of {$this->weekLabel}. Approve each one and it goes into its posting slot.",
            'posts' => $this->posts,
            'url' => url('/tantra/review/'.$this->batchId),
            'button' => 'Review them',
        ]);
    }
}
