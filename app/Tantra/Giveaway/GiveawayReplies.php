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

        $ownId = $unipile->ownId($account);
        $teamComments = BoostAction::query()->whereIn('run_id', fn ($q) => $q->select('id')->from('tantra_boost_runs')->where('post_id', $post->id))
            ->whereNotNull('unipile_ref')->pluck('unipile_ref')->all();
        $teamAccounts = MemberProfile::query()->whereNotNull('unipile_account_id')->pluck('unipile_name')->filter()->map(fn ($n) => Str::lower($n))->all();

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

            if ($commentId === '' || ($ownId !== null && $authorId === $ownId)
                || in_array($commentId, $teamComments, true) || in_array(Str::lower($name), $teamAccounts, true)
                || GiveawayReply::query()->where('post_id', $post->id)->where('comment_id', $commentId)->exists()) {
                continue;
            }

            $first = Str::of($name)->before(' ')->trim()->value() ?: 'there';
            $text = strtr($templates[($queued + crc32($commentId)) % count($templates)], [
                '{first_name}' => $first,
                '{resource}' => (string) ($resource['name'] ?? 'resource'),
            ]);

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

    /** @param array{0: int, 1: int} $hours */
    private static function inWorkingHours(string $timezone, array $hours): bool
    {
        $local = now()->setTimezone($timezone);

        return ! $local->isSunday() && $local->hour >= $hours[0] && $local->hour < $hours[1];
    }
}
