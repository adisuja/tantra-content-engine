<?php

declare(strict_types=1);

namespace App\Tantra\LinkedIn;

use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * tantra: pull a person's identity from LinkedIn right away, when Adi matches
 * their Unipile account on the Team page. Without a user id, everyone due.
 */
final class SyncLinkedInIdentity implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly ?string $userId = null) {}

    public function uniqueId(): string
    {
        return $this->userId ?? 'all';
    }

    public function handle(UnipileClient $unipile, ZernioClient $zernio): void
    {
        LinkedInSync::run($unipile, $zernio, $this->userId, force: $this->userId !== null);
    }
}
