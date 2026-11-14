<?php

declare(strict_types=1);

namespace App\Tantra\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * tantra: builds the standard Engine email (tantra::mail.letter on the shared
 * layout). Every email answers three things: what happened (eyebrow, heading),
 * why it matters to the reader (lead) and the one thing to do (cta).
 * Text is escaped unless a method says Html.
 */
final class Letter
{
    /** @var array<string, mixed> */
    private array $data = ['footer' => []];

    public static function make(string $heading): self
    {
        $letter = new self;
        $letter->data['heading'] = $heading;
        $letter->data['title'] = $heading;

        return $letter;
    }

    public function eyebrow(string $text, string $tone = 'accent'): self
    {
        $this->data['eyebrow'] = $text;
        $this->data['tone'] = $tone;

        return $this;
    }

    public function preheader(string $text): self
    {
        $this->data['preheader'] = $text;

        return $this;
    }

    public function lead(string $text): self
    {
        $this->data['lead'] = $text;

        return $this;
    }

    public function para(string $text): self
    {
        $this->data['paragraphs'][] = e($text);

        return $this;
    }

    public function paraHtml(string $html): self
    {
        $this->data['paragraphs'][] = $html;

        return $this;
    }

    /** @param list<array{0: string, 1: string}> $facts label, value (plain text) */
    public function facts(array $facts): self
    {
        $this->data['facts'] = array_values(array_map(fn (array $f): array => [e($f[0]), e($f[1])], array_filter($facts, fn (array $f): bool => filled($f[1] ?? null))));

        return $this;
    }

    public function quote(string $text, ?string $by = null): self
    {
        $this->data['quote'] = ['text' => $text, 'by' => $by];

        return $this;
    }

    /** @param array<string, mixed> $card from PostCard */
    public function card(?array $card): self
    {
        $this->data['card'] = $card;

        return $this;
    }

    /** @param list<string> $items plain text, shown as numbered steps */
    public function steps(array $items): self
    {
        $this->data['list'] = array_map('e', $items);

        return $this;
    }

    public function cta(string $label, string $url): self
    {
        $this->data['cta'] = ['label' => $label, 'url' => $url];

        return $this;
    }

    public function secondary(string $label, string $url): self
    {
        $this->data['secondary'] = ['label' => $label, 'url' => $url];

        return $this;
    }

    /** Small print under a rule. Stored as 'smallprint': a Mailable's public $note would shadow 'note'. */
    public function note(string $text): self
    {
        $this->data['smallprint'] = e($text);

        return $this;
    }

    public function hero(string $src, string $alt): self
    {
        $this->data['hero'] = ['src' => $src, 'alt' => $alt];

        return $this;
    }

    public function reason(string $text): self
    {
        $this->data['footer']['reason'] = $text;

        return $this;
    }

    public function unsubscribe(string $url, string $label = 'Unsubscribe'): self
    {
        $this->data['footer']['unsubscribe_url'] = $url;
        $this->data['footer']['unsubscribe_label'] = $label;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data + ['preheader' => $this->data['lead'] ?? ''];
    }

    public function content(string $view = 'tantra::mail.letter'): Content
    {
        return new Content(view: $view, with: $this->toArray());
    }

    public function toMailMessage(string $subject): MailMessage
    {
        return (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject)
            ->view('tantra::mail.letter', $this->toArray());
    }
}
