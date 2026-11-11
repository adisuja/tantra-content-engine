<?php

declare(strict_types=1);

namespace App\Tantra\Review\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** tantra: tells every open review screen of a batch that an item changed (PRD 10.2, two reviewers within 5 seconds). */
class ReviewItemChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $batchId,
        public string $itemId,
        public string $kind,
        public ?string $userId,
    ) {}

    public function broadcastAs(): string
    {
        return 'tantra.review.changed';
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tantra.review.batch.{$this->batchId}")];
    }

    /** @return array{item_id: string, kind: string, user_id: string|null} */
    public function broadcastWith(): array
    {
        return ['item_id' => $this->itemId, 'kind' => $this->kind, 'user_id' => $this->userId];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
