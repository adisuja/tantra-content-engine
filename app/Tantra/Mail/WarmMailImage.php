<?php

declare(strict_types=1);

namespace App\Tantra\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** tantra: makes the email copy of an animated GIF ahead of time (App\Tantra\Mail\MailImage). */
final class WarmMailImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public readonly string $path) {}

    public function uniqueId(): string
    {
        return sha1($this->path);
    }

    public function handle(): void
    {
        MailImage::render($this->path, 'gif');
    }
}
