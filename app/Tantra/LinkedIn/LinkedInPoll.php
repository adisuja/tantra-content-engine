<?php

declare(strict_types=1);

namespace App\Tantra\LinkedIn;

use App\Enums\PostPlatform\ContentType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * tantra: the poll a LinkedIn profile or page post may carry (`meta.linkedin_poll`,
 * content types `linkedin_poll` and `linkedin_page_poll`): a question, two to
 * four choices and how long voting stays open. Published as `content.poll` on
 * the Posts API; the post text is the commentary above the poll.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/poll-post-api
 */
final class LinkedInPoll
{
    public const string META_KEY = 'linkedin_poll';

    public const int MIN_OPTIONS = 2;

    public const int MAX_OPTIONS = 4;

    public const int QUESTION_MAX_LENGTH = 140;

    public const int OPTION_MAX_LENGTH = 30;

    public const array DURATIONS = ['ONE_DAY', 'THREE_DAYS', 'ONE_WEEK', 'TWO_WEEKS'];

    public const string DEFAULT_DURATION = 'THREE_DAYS';

    public static function isPoll(mixed $contentType): bool
    {
        return in_array(ContentType::resolve($contentType), [ContentType::LinkedInPoll, ContentType::LinkedInPagePoll], true);
    }

    /**
     * @return list<string>
     */
    public static function options(mixed $meta): array
    {
        return Collection::wrap(data_get($meta, self::META_KEY.'.options'))
            ->filter(fn (mixed $option): bool => is_string($option))
            ->map(fn (string $option): string => Str::trim($option))
            ->filter(fn (string $option): bool => $option !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $poll
     * @return array<string, mixed>
     */
    public static function normalize(array $poll): array
    {
        return Arr::whereNotNull([
            'question' => is_string($poll['question'] ?? null) ? Str::trim($poll['question']) : null,
            'options' => self::options([self::META_KEY => $poll]),
            'duration' => is_string($poll['duration'] ?? null) ? $poll['duration'] : null,
        ]);
    }

    /**
     * What a poll needs before its post is scheduled or published.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function requiredViolation(mixed $meta): ?array
    {
        $key = self::META_KEY;

        if (blank(data_get($meta, "{$key}.question"))) {
            return ["{$key}.question", trans('posts.form.formats.linkedin_poll_question_required')];
        }

        $options = self::options($meta);

        if (count($options) < self::MIN_OPTIONS) {
            return ["{$key}.options", trans('posts.form.formats.linkedin_poll_options_required', ['min' => self::MIN_OPTIONS])];
        }

        if (collect($options)->map(fn (string $option): string => Str::lower($option))->duplicatesStrict()->isNotEmpty()) {
            return ["{$key}.options", trans('posts.form.x.poll.options_distinct')];
        }

        return null;
    }

    /**
     * The `content.poll` object of the Posts API request.
     *
     * @return array{question: string, options: list<array{text: string}>, settings: array{duration: string}}
     */
    public static function payload(mixed $meta): array
    {
        $duration = (string) data_get($meta, self::META_KEY.'.duration');

        return [
            'question' => Str::limit(Str::trim((string) data_get($meta, self::META_KEY.'.question')), self::QUESTION_MAX_LENGTH, ''),
            'options' => array_map(fn (string $text): array => ['text' => $text], self::options($meta)),
            'settings' => ['duration' => in_array($duration, self::DURATIONS, true) ? $duration : self::DEFAULT_DURATION],
        ];
    }

    /**
     * The limits the composer reads from the platform publish config.
     *
     * @return array{minOptions: int, maxOptions: int, questionMaxLength: int, optionMaxLength: int, durations: list<string>, defaultDuration: string}
     */
    public static function limits(): array
    {
        return [
            'minOptions' => self::MIN_OPTIONS,
            'maxOptions' => self::MAX_OPTIONS,
            'questionMaxLength' => self::QUESTION_MAX_LENGTH,
            'optionMaxLength' => self::OPTION_MAX_LENGTH,
            'durations' => self::DURATIONS,
            'defaultDuration' => self::DEFAULT_DURATION,
        ];
    }
}
