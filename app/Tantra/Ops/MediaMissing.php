<?php

declare(strict_types=1);

namespace App\Tantra\Ops;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** tantra: "A scheduled post is missing its image", to the review owner. */
class MediaMissing extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $postId, public string $workspaceName, public string $scheduledAt, public string $short) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A scheduled post is missing its file');
    }

    public function content(): Content
    {
        $at = Carbon::parse($this->scheduledAt)->format('D j M, H:i').' UTC';

        return new Content(view: 'tantra::mail.review-ready', with: [
            'heading' => 'A scheduled post is missing its file',
            'intro' => "The post going out {$at} in {$this->workspaceName} points to an image or document that is no longer in storage, so it would fail to publish. Open it, attach the file again and save.",
            'posts' => $this->short !== '' ? [['id' => 'The post', 'date' => null, 'short' => $this->short, 'revised' => false]] : [],
            'url' => url('/schedule?tab=queue'),
            'button' => 'Open the queue',
        ]);
    }
}
