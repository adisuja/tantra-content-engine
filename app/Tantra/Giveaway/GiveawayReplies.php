<?php

declare(strict_types=1);

namespace App\Tantra\Giveaway;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\User;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * tantra: the hourly pass over giveaway posts (PRD 10.5 step 6). In the author's
 * working hours, every comment without an author answer gets a templated reply
 * in its thread and goes on the "DMs to send" list. On a personal post the
 * author's own Unipile account answers; on a Tantra page post the page answers
 * through Zernio (Spike S6). Team comments are left to their written replies,
 * and a comment the author already answered by hand is left alone.
 */
final class GiveawayReplies
{
    public static function enabled(): bool
    {
        return (bool) config('tantra.autoreply.enabled') && (UnipileClient::configured() || ZernioClient::configured());
    }

    /** @return int replies queued */
    public static function run(UnipileClient $unipile, ?ZernioClient $zernio = null): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $zernio ??= app(ZernioClient::class);

        $config = config('tantra.autoreply');
        $queued = 0;

        $posts = Post::query()
            ->where('status', PostStatus::Published)
            ->whereIn('platform', [Platform::LinkedIn, Platform::ZernioLinkedInPage])
            ->where('published_at', '>=', now()->subDays((int) $config['days']))
            ->whereNotNull('post_group_id')
            ->with('socialAccount')
            ->get();

        foreach ($posts as $post) {
            $item = ReviewItem::query()->where('post_group_id', $post->post_group_id)->first();
            $resource = $item ? data_get($item->payload, 'resource') : null;
            if ($item === null || blank(data_get($resource, 'keyword'))) {
                continue;
            }

            $settings = WorkspaceSettings::query()->find($item->workspace_id);
            if (! self::inWorkingHours($settings->timezone ?? $item->timezone ?? 'UTC', $config['hours'])) {
                continue;
            }

            if ($post->platform === Platform::ZernioLinkedInPage) {
                $source = ZernioClient::configured() ? self::pageSource($zernio, $post) : null;
            } else {
                $author = $settings?->review_owner_user_id ? MemberProfile::query()->find($settings->review_owner_user_id) : null;
                $source = $author !== null && $author->blocker() === null && UnipileClient::configured() ? self::personalSource($unipile, $post, $author) : null;
            }

            if ($source !== null) {
                $queued += self::answer($source, $post, $item, (array) $resource, $config, $queued);
            }
        }

        return $queued;
    }

    /**
     * Comments on a personal post, read through the author's own Unipile account.
     *
     * @return array{via: string, account: string, ref: string, comments: list<array<string, mixed>>}|null
     */
    private static function personalSource(UnipileClient $unipile, Post $post, MemberProfile $author): ?array
    {
        $account = (string) $author->unipile_account_id;
        $native = Boost::nativeRef($post);
        $postRef = $native ? $unipile->postId($account, $native) : null;
        if ($postRef === null) {
            return null;
        }

        // Spike S7: a comment carries `is_sender` and an author with only `id` and `profile_url`.
        $teamIds = self::teamLinkedInIds($unipile);
        $comments = [];
        foreach ($unipile->comments($account, $postRef) as $c) {
            $authorId = (string) (data_get($c, 'author.id') ?? data_get($c, 'author_details.id') ?? '');
            $own = isset($c['is_sender']) ? (bool) $c['is_sender'] : $authorId === self::linkedInId($unipile, $account);
            $answered = (int) ($c['reply_counter'] ?? 0) > 0
                && collect($unipile->replies($account, $postRef, (string) ($c['id'] ?? '')))->contains(fn (array $r): bool => (bool) ($r['is_sender'] ?? false));

            $comments[] = [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) (data_get($c, 'author.name') ?? data_get($c, 'author_details.name') ?? ''),
                'url' => data_get($c, 'author.profile_url') ?? data_get($c, 'author.public_profile_url'),
                'text' => (string) ($c['text'] ?? ''),
                'skip' => $own || $answered || ($authorId !== '' && in_array($authorId, $teamIds, true)),
            ];
        }

        return ['via' => 'unipile', 'account' => $account, 'ref' => $postRef, 'comments' => $comments];
    }

    /**
     * Comments on a Tantra page post, read through Zernio with the page's own
     * replies nested under them.
     *
     * @return array{via: string, account: string, ref: string, comments: list<array<string, mixed>>}|null
     */
    private static function pageSource(ZernioClient $zernio, Post $post): ?array
    {
        $account = (string) $post->socialAccount?->platform_user_id;
        $urn = Boost::nativeRef($post);
        if ($account === '' || $urn === null) {
            return null;
        }

        $comments = [];
        foreach ($zernio->linkedInComments($account, $urn) as $c) {
            $answered = collect((array) ($c['replies'] ?? []))->contains(fn ($r): bool => (bool) data_get($r, 'from.isOwner'));
            $comments[] = [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) data_get($c, 'from.name', ''),
                'url' => data_get($c, 'from.username') ? 'https://www.linkedin.com/in/'.data_get($c, 'from.username') : null,
                'text' => (string) ($c['message'] ?? ''),
                'skip' => (bool) data_get($c, 'from.isOwner') || $answered,
            ];
        }

        return ['via' => 'zernio', 'account' => $account, 'ref' => $urn, 'comments' => $comments];
    }

    /**
     * @param  array{via: string, account: string, ref: string, comments: list<array<string, mixed>>}  $source
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $config
     */
    private static function answer(array $source, Post $post, ReviewItem $item, array $resource, array $config, int $alreadyQueued): int
    {
        $sentToday = GiveawayReply::query()->whereIn('status', ['queued', 'replied'])->where('created_at', '>=', now()->startOfDay())
            ->whereIn('post_id', Post::query()->where('social_account_id', $post->social_account_id)->select('id'))->count();
        $room = min((int) $config['per_run'] - $alreadyQueued, (int) $config['per_day'] - $sentToday);
        if ($room <= 0) {
            return 0;
        }

        $teamComments = BoostAction::query()->whereIn('run_id', fn ($q) => $q->select('id')->from('tantra_boost_runs')->where('post_id', $post->id))
            ->whereNotNull('unipile_ref')->pluck('unipile_ref')->all();
        $teamNames = self::teamNames($post);
        $templates = array_values(array_filter((array) (data_get($item->payload, 'autoreply_templates') ?: $config['templates']), 'is_string'));

        $delay = 0;
        $queued = 0;
        foreach ($source['comments'] as $comment) {
            if ($queued >= $room) {
                break;
            }

            $id = $comment['id'];
            $name = $comment['name'];
            if ($id === '' || $comment['skip']
                || in_array($id, $teamComments, true) || in_array(Str::afterLast(rtrim($id, ')'), ','), $teamComments, true)
                || ($name !== '' && in_array(Str::lower($name), $teamNames, true))
                || GiveawayReply::query()->where('post_id', $post->id)->where('comment_id', $id)->exists()) {
                continue;
            }

            $text = self::render($templates[($queued + crc32($id)) % count($templates)], Str::of($name)->before(' ')->trim()->value(), (string) ($resource['name'] ?? 'resource'));

            $reply = GiveawayReply::query()->create([
                'post_id' => $post->id,
                'review_item_id' => $item->id,
                'via' => $source['via'],
                'comment_id' => $id,
                'commenter_name' => $name ?: null,
                'commenter_url' => $comment['url'],
                'comment_text' => Str::limit($comment['text'], 500),
                'reply_text' => $text,
                'status' => 'queued',
            ]);

            $delay += random_int(...Arr::wrap($config['gap']));
            SendGiveawayReply::dispatch($reply->id, $source['account'], $source['ref'])->delay(now()->addSeconds($delay));
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

    /** @return list<string> lower-cased names of everyone on the Account and their Unipile names */
    private static function teamNames(Post $post): array
    {
        $accountId = $post->workspace?->account_id;

        return collect(MemberProfile::query()->whereNotNull('unipile_account_id')->pluck('unipile_name'))
            ->merge($accountId ? User::query()->where('account_id', $accountId)->pluck('name') : [])
            ->filter()->map(fn ($n) => Str::lower((string) $n))->unique()->values()->all();
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
