<?php

declare(strict_types=1);

namespace App\Tantra\Review\Mail;

use App\Tantra\Mail\Kit;
use App\Tantra\Mail\PostCard;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * tantra: "5 posts ready for review", to a workspace's review owner after an
 * import (PRD 10.1). One card per post (account, slot, copy, first comment,
 * media) with its own link to that post's review screen. There is no approve
 * link: approving needs a signed-in session, and an unsigned approve link in
 * an inbox would be unsafe.
 */
class ReviewReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Cards shown in the email; the rest are counted under "and N more". */
    public const int MAX_CARDS = 8;

    /**
     * @param  list<array{id: string, date: string|null, short: string|null, revised: bool}>  $posts
     * @param  list<string>  $itemIds  the review items, in posting order, for the cards
     */
    public function __construct(
        public string $batchId,
        public string $workspaceName,
        public string $weekLabel,
        public array $posts,
        public array $itemIds = [],
        public ?string $timezone = null,
    ) {}

    public function envelope(): Envelope
    {
        $n = count($this->posts);
        $what = $n === 1 ? '1 post' : "{$n} posts";

        return new Envelope(subject: "{$what} ready for review, week of {$this->weekLabel}");
    }

    public function content(): Content
    {
        $n = count($this->posts);
        $items = $this->itemIds === [] ? collect() : ReviewItem::query()->whereIn('id', $this->itemIds)->with('workspace')->get()
            ->sortBy(fn (ReviewItem $i): int => (int) array_search($i->id, $this->itemIds, true))->values();
        $cards = $items->take(self::MAX_CARDS)->map(fn (ReviewItem $i): array => PostCard::fromReviewItem($i, $this->timezone))->all();
        $networks = $items->countBy(fn (ReviewItem $i): string => Kit::networkLabel($i->platform))
            ->map(fn (int $count, string $network): string => $count > 1 ? "{$count} for {$network}" : "1 for {$network}")->values()->implode(', ');
        $first = $cards[0]['when'] ?? null;
        $url = url('/tantra/review/'.$this->batchId);

        return new Content(view: 'tantra::mail.review-ready', with: [
            'title' => $this->envelope()->subject,
            'preheader' => trim(($networks !== '' ? $networks.'. ' : '').($first ? "The first goes out {$first}." : 'Nothing goes out until you approve it.')),
            'heading' => $n === 1 ? 'A post is ready for your review' : "{$n} posts are ready for your review",
            'lead' => "In {$this->workspaceName}, for the week of {$this->weekLabel}. Each one waits for you: approve it and it goes out in its slot, or ask for changes.",
            'cards' => $cards,
            'legacy' => $cards === [] ? $this->posts : [],
            'more' => max(0, $n - count($cards === [] ? $this->posts : $cards)),
            'url' => $url,
            'button' => $n === 1 ? 'Review the post' : "Review all {$n}",
            'footer' => ['reason' => "You get this because you review posts for {$this->workspaceName}."],
        ]);
    }
}
