<?php

declare(strict_types=1);

namespace App\Tantra\Digest;

use App\Tantra\Mail\Kit;
use App\Tantra\Mail\PostCard;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * tantra: the daily digest (App\Tantra\Console\DailyDigestCommand). What waits
 * for you, what went out, what is coming, how far it travelled, and the one
 * number that shows the work is paying off.
 */
class DailyDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const int MAX_CARDS = 3;

    /**
     * @param  array{waiting: int, item_ids: list<string>, published_24h: int, published_all: int, scheduled_7d: int, views: int, views_delta: int, networks: array<string, int>, standout: array{value: string, label: string, math: string}|null, date: string}  $stats
     */
    public function __construct(
        public string $firstName,
        public array $stats,
        public string $unsubscribeUrl,
        public ?string $timezone = null,
    ) {}

    public function envelope(): Envelope
    {
        $s = $this->stats;
        $parts = array_values(array_filter([
            $s['waiting'] > 0 ? ($s['waiting'] === 1 ? '1 post to review' : "{$s['waiting']} posts to review") : null,
            $s['published_24h'] > 0 ? ($s['published_24h'] === 1 ? '1 went live' : "{$s['published_24h']} went live") : null,
            $s['views_delta'] > 0 ? '+'.Kit::compact($s['views_delta']).' views' : null,
            $s['waiting'] === 0 && $s['published_24h'] === 0 && $s['scheduled_7d'] > 0 ? "{$s['scheduled_7d']} scheduled this week" : null,
        ]));

        return new Envelope(subject: 'Your day on Tantra: '.($parts !== [] ? implode(', ', $parts) : 'all quiet'));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $s = $this->stats;
        $items = $s['item_ids'] === [] ? collect() : ReviewItem::query()->whereIn('id', array_slice($s['item_ids'], 0, self::MAX_CARDS))->with('workspace')->get()
            ->sortBy(fn (ReviewItem $i): int => (int) array_search($i->id, $s['item_ids'], true))->values();
        $max = max([1, ...array_values($s['networks'])]);

        return new Content(view: 'tantra::mail.digest', with: [
            'title' => $this->envelope()->subject,
            'preheader' => $s['standout'] !== null ? $s['standout']['value'].' '.$s['standout']['label'].'.' : 'Your posts, views and what waits for you today.',
            'firstName' => $this->firstName,
            's' => $s,
            'cards' => $items->map(fn (ReviewItem $i): array => PostCard::fromReviewItem($i, $this->timezone))->all(),
            'more' => max(0, $s['waiting'] - min(self::MAX_CARDS, $items->count())),
            'networks' => collect($s['networks'])->map(fn (int $views, string $network): array => [
                'label' => Kit::networkLabel($network), 'icon' => Kit::networkIcon($network), 'views' => Kit::compact($views), 'pct' => max(2, (int) round($views / $max * 100)),
            ])->values()->all(),
            'reviewUrl' => url('/tantra/review'),
            'insightsUrl' => url('/tantra/insights'),
            'footer' => [
                'reason' => 'You get this once a day because you are a member of a Tantra workspace.',
                'unsubscribe_url' => $this->unsubscribeUrl,
                'unsubscribe_label' => 'Turn off the daily digest',
            ],
        ]);
    }
}
