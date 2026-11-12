<?php

declare(strict_types=1);

namespace App\Tantra\Giveaway;

use App\Tantra\Boost\Boost;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Unipile\UnipileException;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** tantra: posts one templated giveaway reply in the comment's thread, once: from the author's Unipile account, or as the Tantra page through Zernio. */
class SendGiveawayReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $replyId, public string $accountId, public string $postRef)
    {
        $this->onQueue(Boost::QUEUE);
    }

    public function handle(UnipileClient $unipile, ?ZernioClient $zernio = null): void
    {
        $reply = GiveawayReply::query()->find($this->replyId);
        if ($reply === null || $reply->status !== 'queued') {
            return;
        }
        if (! GiveawayReplies::enabled()) {
            $reply->forceFill(['status' => 'skipped', 'error' => 'auto-replies are switched off'])->save();

            return;
        }

        if ($reply->via === 'zernio') {
            // The Tantra page answers as itself: accountId is the page's Zernio account, postRef its post urn.
            $response = ($zernio ?? app(ZernioClient::class))->commentAsPage($this->accountId, $this->postRef, ZernioClient::commentUrn($this->postRef, $reply->comment_id), (string) $reply->reply_text, 'tantra-giveaway-'.$reply->id);
            $ok = $response->successful() && $response->json('success') !== false;
            $reply->forceFill($ok
                ? ['status' => 'replied', 'reply_id' => (string) $response->json('data.commentId'), 'replied_at' => now(), 'error' => null]
                : ['status' => 'failed', 'error' => 'Zernio answered '.$response->status().': '.mb_substr((string) $response->body(), 0, 250)])->save();

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
