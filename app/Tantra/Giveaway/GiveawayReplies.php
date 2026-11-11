<?php

declare(strict_types=1);

namespace App\Tantra\Giveaway;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * tantra: the hourly pass over giveaway posts (PRD 10.5 step 6). In the author's
 * working hours, every comment without an author answer gets a templated reply
 * from the author's own account, spaced out, and goes on the "DMs to send" list.
 * Team comments are left to their written thread replies.
 */
final class GiveawayReplies
{
    public static function enabled(): bool
    {
        return (bool) config('tantra.autoreply.enabled') && UnipileClient::configured();
    }

    /** @return int replies queued */
    public static function run(UnipileClient $unipile): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $config = config('tantra.autoreply');
        $queued = 0;

        $posts = Post::query()
            ->where('status', PostStatus::Published)
            ->where('platform', Platform::LinkedIn)
            ->where('published_at', '>=', now()->subDays((int) $config['days']))
            ->whereNotNull('post_group_id')
            ->get();

        foreach ($posts as $post) {
            $item = ReviewItem::query()->where('post_group_id', $post->post_group_id)->first();
            $resource = $item ? data_get($item->payload, 'resource') : null;
            if ($item === null || blank(data_get($resource, 'keyword'))) {
                continue;
            }

            $settings = WorkspaceSettings::query()->find($item->workspace_id);
            $author = $settings?->review_owner_user_id ? MemberProfile::query()->find($settings->review_owner_user_id) : null;
            if ($author === null || $author->blocker() !== null || ! self::inWorkingHours($settings->timezone ?? $item->timezone ?? 'UTC', $config['hours'])) {
                continue;
            }

            $queued += self::forPost($unipile, $post, $item, $author, (array) $resource, $config, $queued);
        }

        return $queued;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $config
     */
    private static function forPost(UnipileClient $unipile, Post $post, ReviewItem $item, MemberProfile $author, array $resource, array $config, int $alreadyQueued): int
    {
        $account = (string) $author->unipile_account_id;
        $native = Boost::nativeRef($post);
        $postRef = $native ? $unipile->postId($account, $native) : null;
        if ($postRef === null) {
            return 0;
        }

        $sentToday = GiveawayReply::query()->whereIn('status', ['queued', 'replied'])->where('created_at', '>=', now()->startOfDay())
            ->whereIn('post_id', Post::query()->where('social_account_id', $post->social_account_id)->select('id'))->count();
        $room = min((int) $config['per_run'] - $alreadyQueued, (int) $config['per_day'] - $sentToday);
        if ($room <= 0) {
            return 0;
        }

        // Spike S7: a comment carries `is_sender` and an author with only `id` and
        // `profile_url`, no name. Team members are known by their LinkedIn ids.
        $teamComments = BoostAction::query()->whereIn('run_id', fn ($q) => $q->select('id')->from('tantra_boost_runs')->where('post_id', $post->id))
            ->whereNotNull('unipile_ref')->pluck('unipile_ref')->all();
        $teamIds = self::teamLinkedInIds($unipile);
        $teamNames = MemberProfile::query()->whereNotNull('unipile_account_id')->pluck('unipile_name')->filter()->map(fn ($n) => Str::lower($n))->all();

        $templates = array_values(array_filter((array) (data_get($item->payload, 'autoreply_templates') ?: $config['templates']), 'is_string'));
        $delay = 0;
        $queued = 0;

        foreach ($unipile->comments($account, $postRef) as $comment) {
            if ($queued >= $room) {
                break;
            }

            $commentId = (string) ($comment['id'] ?? '');
            $authorId = (string) (data_get($comment, 'author.id') ?? data_get($comment, 'author_details.id') ?? '');
            $name = (string) (data_get($comment, 'author.name') ?? data_get($comment, 'author_details.name') ?? '');
            $own = isset($comment['is_sender']) ? (bool) $comment['is_sender'] : $authorId === self::linkedInId($unipile, $account);

            if ($commentId === '' || $own
                || in_array($commentId, $teamComments, true) || ($authorId !== '' && in_array($authorId, $teamIds, true))
                || ($name !== '' && in_array(Str::lower($name), $teamNames, true))
                || GiveawayReply::query()->where('post_id', $post->id)->where('comment_id', $commentId)->exists()) {
                continue;
            }

            $text = self::render($templates[($queued + crc32($commentId)) % count($templates)], Str::of($name)->before(' ')->trim()->value(), (string) ($resource['name'] ?? 'resource'));

            $reply = GiveawayReply::query()->create([
                'post_id' => $post->id,
                'review_item_id' => $item->id,
                'comment_id' => $commentId,
                'commenter_name' => $name ?: null,
                'commenter_url' => data_get($comment, 'author.profile_url') ?? data_get($comment, 'author.public_profile_url'),
                'comment_text' => Str::limit((string) ($comment['text'] ?? ''), 500),
                'reply_text' => $text,
                'status' => 'queued',
            ]);

            $delay += random_int(...Arr::wrap($config['gap']));
            SendGiveawayReply::dispatch($reply->id, $account, $postRef)->delay(now()->addSeconds($delay));
            $queued++;
        }

        return $queued;
    }

    /** Fills a template. With no name (LinkedIn often gives none) the greeting drops out cleanly. */
    public static function render(string $template, string $firstName, string $resource): string
    {
        $text = str_replace('{resource}', $resource, $template);
        if ($firstName !== '') {
            return str_replace('{first_name}', $firstName, $text);
        }

        $text = (string) preg_replace('/,?\s*\{first_name\}/', '', $text);
        $text = ltrim((string) preg_replace('/^[,:]\s*/', '', $text));

        return Str::ucfirst($text);
    }

    /** @return list<string> the LinkedIn ids of everyone with a confirmed Unipile account */
    private static function teamLinkedInIds(UnipileClient $unipile): array
    {
        return MemberProfile::query()->whereNotNull('unipile_confirmed_at')->pluck('unipile_account_id')->filter()
            ->map(fn (string $acc): ?string => self::linkedInId($unipile, $acc))->filter()->values()->all();
    }

    /** An account's own LinkedIn id, kept once read: users/me is rate limited (S7 got a 429). */
    private static function linkedInId(UnipileClient $unipile, string $account): ?string
    {
        $key = "tantra:unipile:own:{$account}";
        $id = Cache::get($key);
        if ($id === null && ($id = $unipile->ownId($account)) !== null) {
            Cache::forever($key, $id);
        }

        return $id;
    }

    /** @param array{0: int, 1: int} $hours */
    private static function inWorkingHours(string $timezone, array $hours): bool
    {
        $local = now()->setTimezone($timezone);

        return ! $local->isSunday() && $local->hour >= $hours[0] && $local->hour < $hours[1];
    }
}
