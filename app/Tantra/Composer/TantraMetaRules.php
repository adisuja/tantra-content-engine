<?php

declare(strict_types=1);

namespace App\Tantra\Composer;

use App\Enums\SocialAccount\Platform;
use App\Tantra\LinkedIn\LinkedInPoll;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * tantra: the per-network post settings the Engine adds on top of TryPost
 * (format gaps, 2026-11-14), spread into `PostPlatformMetaRules` so the web,
 * REST API and MCP accept the same keys. Each key is read only by the
 * publisher of its network; a key a network does not know is ignored there.
 */
final class TantraMetaRules
{
    /** LinkedIn member posts may be shown to connections only; Mastodon has its own four. */
    public const array LINKEDIN_VISIBILITY = ['PUBLIC', 'CONNECTIONS'];

    public const array MASTODON_VISIBILITY = ['public', 'unlisted', 'private', 'direct'];

    /** Threads `reply_control`; Bluesky maps the same choices onto a threadgate. */
    public const array REPLY_CONTROL = ['everyone', 'accounts_you_follow', 'mentioned_only', 'parent_post_author_only', 'followers_only'];

    /** X `reply_settings`. */
    public const array X_REPLY_SETTINGS = ['following', 'mentionedUsers', 'subscribers', 'verified'];

    /** Bluesky self-labels (content warnings). */
    public const array BLUESKY_SELF_LABELS = ['sexual', 'nudity', 'porn', 'graphic-media'];

    /** YouTube counts every tag plus a separator against 500 characters. */
    public const int YOUTUBE_TAGS_MAX_TOTAL = 500;

    /**
     * Poll limits per network: [min options, max options, max option length].
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    public const array POLLS = [
        'threads_poll' => [2, 4, 25],
        'mastodon_poll' => [2, 4, 50],
        'telegram_poll' => [2, 10, 100],
        'discord_poll' => [2, 10, 55],
    ];

    /** Mastodon poll length, in seconds: 5 minutes to 30 days. */
    public const int MASTODON_POLL_MIN_SECONDS = 300;

    public const int MASTODON_POLL_MAX_SECONDS = 2_592_000;

    public const int MASTODON_POLL_DEFAULT_SECONDS = 86_400;

    /** Discord poll length, in hours: up to 32 days. */
    public const int DISCORD_POLL_MAX_HOURS = 768;

    public const int DISCORD_POLL_DEFAULT_HOURS = 24;

    /** An X post or a Bluesky post, pasted from the browser. */
    public const string X_STATUS_PATTERN = '#^https?://(?:www\.|mobile\.)?(?:x|twitter)\.com/[A-Za-z0-9_]+/status(?:es)?/(\d+)#i';

    public const string BLUESKY_POST_PATTERN = '#^https?://(?:www\.)?bsky\.app/profile/([^/?\#]+)/post/([A-Za-z0-9]+)#i';

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'meta'): array
    {
        $rules = [
            // LinkedIn polls.
            "{$prefix}.linkedin_poll" => ['sometimes', 'nullable', 'array'],
            "{$prefix}.linkedin_poll.question" => ['sometimes', 'nullable', 'string', 'max:'.LinkedInPoll::QUESTION_MAX_LENGTH],
            "{$prefix}.linkedin_poll.options" => ['sometimes', 'nullable', 'array', 'max:'.LinkedInPoll::MAX_OPTIONS],
            "{$prefix}.linkedin_poll.options.*" => ['nullable', 'string', 'max:'.LinkedInPoll::OPTION_MAX_LENGTH],
            "{$prefix}.linkedin_poll.duration" => ['sometimes', 'nullable', 'string', Rule::in(LinkedInPoll::DURATIONS)],

            // Video title (LinkedIn, Facebook), visibility (LinkedIn member posts, Mastodon), reshares.
            "{$prefix}.video_title" => ['sometimes', 'nullable', 'string', 'max:255'],
            "{$prefix}.visibility" => ['sometimes', 'nullable', 'string', Rule::in([...self::LINKEDIN_VISIBILITY, ...self::MASTODON_VISIBILITY])],
            "{$prefix}.disable_reshare" => ['sometimes', 'boolean'],

            // YouTube long-form.
            "{$prefix}.tags" => ['sometimes', 'nullable', 'array', self::youtubeTagsTotal(...)],
            "{$prefix}.tags.*" => ['string', 'max:100'],
            "{$prefix}.playlist_id" => ['sometimes', 'nullable', 'string', 'max:128'],
            "{$prefix}.thumbnail_url" => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],

            // Threads and Bluesky reply control, Threads link card and spoiler.
            "{$prefix}.reply_control" => ['sometimes', 'nullable', 'string', Rule::in(self::REPLY_CONTROL)],
            "{$prefix}.link_attachment" => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            "{$prefix}.spoiler" => ['sometimes', 'boolean'],

            // X.
            "{$prefix}.reply_settings" => ['sometimes', 'nullable', 'string', Rule::in(self::X_REPLY_SETTINGS)],
            "{$prefix}.quote_url" => ['sometimes', 'nullable', 'string', 'max:2048', self::quoteUrl(...)],
            "{$prefix}.paid_partnership" => ['sometimes', 'boolean'],

            // Mastodon and Bluesky.
            "{$prefix}.language" => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/'],
            "{$prefix}.sensitive" => ['sometimes', 'boolean'],
            "{$prefix}.self_labels" => ['sometimes', 'nullable', 'array'],
            "{$prefix}.self_labels.*" => ['string', Rule::in(self::BLUESKY_SELF_LABELS)],
            "{$prefix}.mastodon_poll.expires_in" => ['sometimes', 'nullable', 'integer', 'between:'.self::MASTODON_POLL_MIN_SECONDS.','.self::MASTODON_POLL_MAX_SECONDS],
            "{$prefix}.mastodon_poll.multiple" => ['sometimes', 'boolean'],

            // Facebook, Instagram.
            "{$prefix}.location_id" => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{1,32}$/'],
            "{$prefix}.audio_name" => ['sometimes', 'nullable', 'string', 'max:100'],
            "{$prefix}.cover_url" => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],

            // Telegram.
            "{$prefix}.telegram_poll.question" => ['sometimes', 'nullable', 'string', 'max:300'],
            "{$prefix}.disable_notification" => ['sometimes', 'boolean'],
            "{$prefix}.protect_content" => ['sometimes', 'boolean'],
            "{$prefix}.has_spoiler" => ['sometimes', 'boolean'],
            "{$prefix}.pin" => ['sometimes', 'boolean'],

            // Discord.
            "{$prefix}.discord_poll.question" => ['sometimes', 'nullable', 'string', 'max:300'],
            "{$prefix}.discord_poll.duration_hours" => ['sometimes', 'nullable', 'integer', 'between:1,'.self::DISCORD_POLL_MAX_HOURS],

            // TikTok photo drafts, Pinterest board sections.
            "{$prefix}.upload_to_inbox" => ['sometimes', 'boolean'],
            "{$prefix}.board_section_id" => ['sometimes', 'nullable', 'string', 'max:64'],
        ];

        foreach (self::POLLS as $key => [, $max, $length]) {
            $rules["{$prefix}.{$key}"] = ['sometimes', 'nullable', 'array'];
            $rules["{$prefix}.{$key}.options"] = ['sometimes', 'nullable', 'array', "max:{$max}"];
            $rules["{$prefix}.{$key}.options.*"] = ['nullable', 'string', "max:{$length}"];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = 'meta'): array
    {
        return [
            "{$prefix}.linkedin_poll.question.max" => __('posts.form.formats.linkedin_poll_question_max', ['max' => LinkedInPoll::QUESTION_MAX_LENGTH]),
            "{$prefix}.linkedin_poll.options.max" => __('posts.form.x.poll.options_max', ['max' => LinkedInPoll::MAX_OPTIONS]),
            "{$prefix}.linkedin_poll.options.*.max" => __('posts.form.x.poll.choice_max', ['max' => LinkedInPoll::OPTION_MAX_LENGTH]),
            "{$prefix}.language.regex" => __('posts.form.formats.language_invalid'),
        ];
    }

    /**
     * Keys stored trimmed and in one shape whatever the entry point sent.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function normalize(array $meta): array
    {
        if (is_array($meta[LinkedInPoll::META_KEY] ?? null)) {
            $meta[LinkedInPoll::META_KEY] = LinkedInPoll::normalize($meta[LinkedInPoll::META_KEY]);
        }

        foreach (array_keys(self::POLLS) as $key) {
            if (is_array($meta[$key] ?? null)) {
                $meta[$key] = [...$meta[$key], 'options' => self::choices($meta[$key]['options'] ?? [])];
            }
        }

        if (is_array($meta['tags'] ?? null)) {
            $meta['tags'] = self::youtubeTags($meta['tags']);
        }

        foreach (['quote_url', 'link_attachment', 'thumbnail_url', 'cover_url'] as $key) {
            if (is_string($meta[$key] ?? null)) {
                $meta[$key] = Str::trim($meta[$key]);
            }
        }

        return $meta;
    }

    /**
     * A poll a network takes from these settings that is set but cannot go out.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function requiredViolation(?Platform $platform, mixed $meta): ?array
    {
        $key = match ($platform) {
            Platform::Threads => 'threads_poll',
            Platform::Mastodon => 'mastodon_poll',
            Platform::Telegram => 'telegram_poll',
            Platform::Discord => 'discord_poll',
            default => null,
        };

        if ($key === null || ! self::hasPoll($meta, $key)) {
            return null;
        }

        [$min] = self::POLLS[$key];
        $options = self::choices(data_get($meta, "{$key}.options"));

        if (count($options) < $min) {
            return ["{$key}.options", trans('posts.form.x.poll.options_required', ['min' => $min])];
        }

        if (collect($options)->map(fn (string $o): string => Str::lower($o))->duplicatesStrict()->isNotEmpty()) {
            return ["{$key}.options", trans('posts.form.x.poll.options_distinct')];
        }

        if (in_array($key, ['telegram_poll', 'discord_poll'], true) && blank(data_get($meta, "{$key}.question"))) {
            return ["{$key}.question", trans('posts.form.formats.poll_question_required')];
        }

        return null;
    }

    /**
     * Whether a poll was started: a choice was typed in it.
     */
    public static function hasPoll(mixed $meta, string $key): bool
    {
        return self::choices(data_get($meta, "{$key}.options")) !== [];
    }

    /**
     * @return list<string>
     */
    public static function choices(mixed $options): array
    {
        return Collection::wrap($options)
            ->filter(fn (mixed $option): bool => is_string($option))
            ->map(fn (string $option): string => Str::trim($option))
            ->filter(fn (string $option): bool => $option !== '')
            ->values()
            ->all();
    }

    /**
     * YouTube tags: trimmed, without a leading #, each once.
     *
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    public static function youtubeTags(array $tags): array
    {
        return collect($tags)
            ->filter(fn (mixed $tag): bool => is_string($tag))
            ->map(fn (string $tag): string => ltrim(Str::trim($tag), '#'))
            ->filter()
            ->unique(fn (string $tag): string => Str::lower($tag))
            ->values()
            ->all();
    }

    /**
     * How YouTube measures the tag list: each tag, quoted when it has a space,
     * plus a comma between tags.
     *
     * @param  array<int, mixed>  $tags
     */
    public static function youtubeTagsLength(array $tags): int
    {
        $tags = self::youtubeTags($tags);

        return collect($tags)->sum(fn (string $tag): int => mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0))
            + max(0, count($tags) - 1);
    }

    /**
     * The X post id of a pasted X URL, or null.
     */
    public static function xQuoteId(mixed $url): ?string
    {
        return is_string($url) && preg_match(self::X_STATUS_PATTERN, Str::trim($url), $m) === 1 ? $m[1] : null;
    }

    /**
     * The handle (or DID) and record key of a pasted Bluesky post URL, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function blueskyQuote(mixed $url): ?array
    {
        return is_string($url) && preg_match(self::BLUESKY_POST_PATTERN, Str::trim($url), $m) === 1
            ? [rawurldecode($m[1]), $m[2]]
            : null;
    }

    public static function documentation(): string
    {
        return implode(' ', [
            'LinkedIn polls: content_type linkedin_poll (profile) or linkedin_page_poll (page), no media, meta.linkedin_poll {question (required, up to '.LinkedInPoll::QUESTION_MAX_LENGTH.'), options ('.LinkedInPoll::MIN_OPTIONS.' to '.LinkedInPoll::MAX_OPTIONS.' choices up to '.LinkedInPoll::OPTION_MAX_LENGTH.' characters), duration '.implode('|', LinkedInPoll::DURATIONS).' (default '.LinkedInPoll::DEFAULT_DURATION.')}; the post text is shown above the poll.',
            'LinkedIn: video_title (title of a video post), visibility PUBLIC|CONNECTIONS (profile posts only), disable_reshare (true stops reshares). Facebook: video_title (title of a video post).',
            'YouTube: content_type youtube_video for a long-form video (16:9, no 3-minute cap; youtube_short stays a Short); tags (list, '.self::YOUTUBE_TAGS_MAX_TOTAL.' characters in total), playlist_id (adds the video to that playlist), thumbnail_url (image set as the custom thumbnail).',
            'Threads: reply_control '.implode('|', self::REPLY_CONTROL).', threads_poll {options 2 to 4, up to 25 characters}, link_attachment (URL shown as the link card), spoiler (blurs the media), is_ai_generated.',
            'X: reply_settings '.implode('|', self::X_REPLY_SETTINGS).' (omit for everyone), quote_url (an x.com post URL to quote), paid_partnership.',
            'Mastodon: visibility public|unlisted|private|direct, mastodon_poll {options 2 to 4, expires_in seconds, multiple}, language (ISO 639 code), sensitive.',
            'Bluesky: language (ISO 639 code), self_labels (list of '.implode('|', self::BLUESKY_SELF_LABELS).'), reply_control (same values as Threads, sent as a threadgate), quote_url (a bsky.app post URL to quote).',
            'Instagram: location_id (Facebook place id), audio_name (reels: name of the original audio), cover_url (reels: cover image URL). Telegram: telegram_poll {question, options 2 to 10} sent after the post, disable_notification, protect_content, has_spoiler, pin. Discord: discord_poll {question, options 2 to 10, duration_hours}. TikTok: title (photo posts), upload_to_inbox (send to the TikTok inbox as a draft). Pinterest: board_section_id.',
        ]);
    }

    private static function youtubeTagsTotal(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value) && self::youtubeTagsLength($value) > self::YOUTUBE_TAGS_MAX_TOTAL) {
            $fail(__('posts.form.formats.youtube_tags_max', ['max' => self::YOUTUBE_TAGS_MAX_TOTAL]));
        }
    }

    private static function quoteUrl(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value) && self::xQuoteId($value) === null && self::blueskyQuote($value) === null) {
            $fail(__('posts.form.formats.quote_url_invalid'));
        }
    }
}
