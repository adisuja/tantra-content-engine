<?php

declare(strict_types=1);

namespace App\Tantra\Giveaway;

use App\Tantra\Boost\Boost;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Unipile\UnipileException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** tantra: posts one templated giveaway reply in the comment's thread, once. */
class SendGiveawayReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $replyId, public string $accountId, public string $postRef)
    {
        $this->onQueue(Boost::QUEUE);
    }

    public function handle(UnipileClient $unipile): void
    {
        $reply = GiveawayReply::query()->find($this->replyId);
        if ($reply === null || $reply->status !== 'queued') {
            return;
        }
        if (! GiveawayReplies::enabled()) {
            $reply->forceFill(['status' => 'skipped', 'error' => 'auto-replies are switched off'])->save();

            return;
        }

        try {
            $id = $unipile->reply($this->accountId, $this->postRef, $reply->comment_id, (string) $reply->reply_text);
            $reply->forceFill(['status' => 'replied', 'reply_id' => $id, 'replied_at' => now(), 'error' => null])->save();
        } catch (UnipileException $e) {
            $reply->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }
    }
}
