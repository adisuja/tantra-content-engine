<?php

declare(strict_types=1);

namespace App\Tantra\Manual;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** tantra: "Post to X now", when a manual task falls due (PRD 12). */
class ManualTaskDue extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $taskId,
        public string $kind,
        public string $postId,
        public string $text,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'linkedin_poll' ? 'Post your LinkedIn poll now' : 'Post to X now');
    }

    public function content(): Content
    {
        $poll = $this->kind === 'linkedin_poll';

        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => $poll ? 'Your LinkedIn poll is due' : 'Your X post is due',
            'intro' => $poll
                ? 'LinkedIn polls go out by hand. Copy the question and options, post them from your profile, then mark the task posted.'
                : 'X posts go out by hand. Copy the text, attach the image, post it, then paste the link back so the task closes.',
            'posts' => [['id' => $this->postId, 'date' => null, 'short' => Str::limit($this->text, 140)]],
            'url' => url('/tantra/tasks'),
            'button' => 'Open the task',
        ]);
    }
}
