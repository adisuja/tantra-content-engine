<?php

declare(strict_types=1);

namespace App\Tantra\Create;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** tantra: writes one Content > Create piece and pushes it to Review (CreateFromFormat::run). Tried once: a failure is shown in words. */
final class CreatePiece implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly string $id) {}

    public function handle(): void
    {
        CreateFromFormat::run($this->id);
    }
}
