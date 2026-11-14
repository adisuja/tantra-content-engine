<?php

declare(strict_types=1);

namespace App\Tantra\Manual;

use App\Tantra\Mail\Letter;
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

        return Letter::make($poll ? 'Your LinkedIn poll is due now' : 'Your X post is due now')
            ->eyebrow('Due now', 'warn')
            ->lead($poll
                ? 'LinkedIn does not let apps post polls, so this one goes out by hand. It takes two minutes and keeps your week on plan.'
                : 'This X post goes out by hand. It takes two minutes and keeps your week on plan.')
            ->quote(Str::limit($this->text, 280), $poll ? 'The poll question' : 'The post')
            ->steps($poll
                ? ['Open the task and copy the question and options.', 'Post the poll from your LinkedIn profile.', 'Mark the task posted so the follow ups start.']
                : ['Open the task and copy the text.', 'Post it on X with the image.', 'Paste the post link back so the task closes.'])
            ->cta('Open the task', url('/tantra/tasks'))
            ->reason('You get this because this post is on your schedule.')
            ->content();
    }
}
