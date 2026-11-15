<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Tantra\Review\Models\ReviewItem;

/**
 * tantra: the follow-up messages that send a post's resource (the giveaway PDF or
 * page) to the people who asked for it, one per channel. The content kit pushes
 * them with the post (payload.messages); the review owner can edit each one on the
 * review screen (message_overrides), and every follow-up reads them from here, so
 * an edit is what goes out. Placeholders stay as written: {first_name}, [POST LINK].
 */
final class Messages
{
    /** Channel key => [label, max length]. */
    public const CHANNELS = [
        'linkedin_dm' => ['LinkedIn DM', 8000],
        'linkedin_connect' => ['LinkedIn connection note', 300],
        'email_subject' => ['Email subject', 200],
        'email_body' => ['Email body', 10000],
        'whatsapp' => ['WhatsApp', 4096],
        'sms' => ['SMS', 480],
    ];

    /** The Content Studio's dm block (posts.json) => the Engine's channel keys. */
    public const STUDIO_DM = [
        'message' => 'linkedin_dm',
        'connect' => 'linkedin_connect',
        'email_subject' => 'email_subject',
        'email_body' => 'email_body',
        'whatsapp' => 'whatsapp',
        'sms' => 'sms',
    ];

    /**
     * A pushed post with its follow-up messages filled from the Studio's dm block wherever the push sent none, so a
     * batch pushed as the Studio writes it (dm.message, dm.connect, dm.email_subject, dm.email_body) keeps them.
     * Pushed messages win; WhatsApp reuses the LinkedIn DM, as the content kit does. The dm block itself is kept.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    public static function fromStudio(array $post): array
    {
        $dm = array_filter((array) ($post['dm'] ?? []), fn (mixed $v): bool => is_string($v) && trim($v) !== '');
        if ($dm === []) {
            return $post;
        }
        $messages = array_filter((array) ($post['messages'] ?? []), fn (mixed $v): bool => is_string($v) && trim($v) !== '');
        foreach (self::STUDIO_DM as $from => $channel) {
            if (! isset($messages[$channel]) && isset($dm[$from])) {
                $messages[$channel] = $dm[$from];
            }
        }
        if (! isset($messages['whatsapp']) && isset($dm['message'])) {
            $messages['whatsapp'] = $dm['message'];
        }
        $post['messages'] = $messages;

        return $post;
    }

    /**
     * Every channel's message as it will be sent: the review owner's edit, else the
     * pushed text, else a plain default built from the resource. Empty when the post
     * has no resource and nothing was pushed.
     *
     * @return array<string, array{label: string, text: string, edited: bool, max: int}>
     */
    public static function for(ReviewItem $item): array
    {
        $pushed = (array) data_get($item->payload, 'messages', []);
        $overrides = (array) ($item->message_overrides ?? []);
        $defaults = self::defaults($item);

        $out = [];
        foreach (self::CHANNELS as $key => [$label, $max]) {
            $base = (string) ($pushed[$key] ?? $defaults[$key] ?? '');
            $text = array_key_exists($key, $overrides) ? (string) $overrides[$key] : $base;
            if ($text === '' && $base === '') {
                continue;
            }
            $out[$key] = ['label' => $label, 'text' => $text, 'edited' => array_key_exists($key, $overrides) && $overrides[$key] !== $base, 'max' => $max];
        }

        return $out;
    }

    /** One channel's text with the placeholders filled, ready to send. */
    public static function render(ReviewItem $item, string $channel, string $firstName = '', ?string $postUrl = null): string
    {
        $text = self::for($item)[$channel]['text'] ?? '';
        $text = str_replace('{first_name}', $firstName !== '' ? $firstName : 'there', $text);

        return $postUrl !== null ? str_replace('[POST LINK]', $postUrl, $text) : $text;
    }

    /**
     * Plain defaults from the resource, for posts pushed before the kit sent messages.
     *
     * @return array<string, string>
     */
    private static function defaults(ReviewItem $item): array
    {
        $resource = (array) data_get($item->payload, 'resource', []);
        $name = trim((string) ($resource['name'] ?? ''));
        if ($name === '') {
            return [];
        }
        $link = (string) ($resource['url'] ?? '') ?: $item->previewUrl();
        $keyword = trim((string) ($resource['keyword'] ?? ''));
        $asked = $keyword !== '' ? "thanks for commenting {$keyword}" : 'thanks for asking';
        $what = trim((string) ($resource['what'] ?? ''));

        return [
            'linkedin_dm' => "Hi {first_name}, {$asked}.\n\nHere's {$name}:\n{$link}".($what !== '' ? "\n\n{$what}" : ''),
            'linkedin_connect' => "Hi {first_name}, {$asked}. LinkedIn only lets me message connections, so accept this and I'll send {$name} straight over.",
            'email_subject' => "Here's {$name}",
            'email_body' => "Hi {first_name},\n\nHere's {$name}, as promised:\n{$link}".($what !== '' ? "\n\n{$what}" : '')."\n\nHit reply and tell me what you think.",
            'whatsapp' => "Hi {first_name}, here's {$name}, as promised:\n{$link}",
            'sms' => "Hi {first_name}, here's {$name}: {$link}",
        ];
    }
}
