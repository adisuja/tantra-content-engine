<?php

declare(strict_types=1);

namespace App\Tantra\Review\Mail;

use App\Tantra\Mail\Letter;
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
        $n = array_sum(array_map(fn (array $p): int => count($p['numbers']), $this->posts));
        $lines = [];
        foreach ($this->posts as $post) {
            foreach ($post['numbers'] as $number) {
                $lines[] = $number.(filled($post['short'] ?? null) ? ' (in "'.$post['short'].'")' : '');
            }
        }

        return Letter::make($n === 1 ? 'A number of yours needs your sign off' : "{$n} numbers of yours need your sign off")
            ->eyebrow('Sign off needed', 'warn')
            ->lead("These posts in {$this->workspaceName} quote your numbers. They stay on hold until you confirm each one is right, so nothing goes out with a figure you did not check.")
            ->steps($lines)
            ->cta($n === 1 ? 'Check and sign off' : 'Check and sign them off', url('/tantra/review/'.$this->batchId))
            ->reason('You get this because these numbers are attributed to you.')
            ->content();
    }
}
