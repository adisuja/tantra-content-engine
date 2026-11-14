<?php

declare(strict_types=1);

namespace App\Tantra\Ops;

use App\Tantra\Mail\Letter;
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
        return new Envelope(subject: 'Fix before '.Carbon::parse($this->scheduledAt)->format('D j M, H:i').' UTC: a post is missing its file');
    }

    public function content(): Content
    {
        $at = Carbon::parse($this->scheduledAt)->format('D j M, H:i').' UTC';

        $letter = Letter::make('A scheduled post is missing its file')
            ->eyebrow('Will fail to publish', 'bad')
            ->lead("The post going out {$at} in {$this->workspaceName} points to an image or document that is no longer in storage. If it stays that way, the post fails at its slot.")
            ->facts([['Goes out', $at], ['Workspace', $this->workspaceName]]);
        if ($this->short !== '') {
            $letter->quote($this->short, 'The post');
        }

        return $letter
            ->para('Open it, attach the file again and save. The slot stays the same.')
            ->cta('Fix the post', url('/schedule?tab=queue'))
            ->reason("You get this because you review posts for {$this->workspaceName}.")
            ->content();
    }
}
