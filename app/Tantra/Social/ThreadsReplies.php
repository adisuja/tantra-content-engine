<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Exceptions\Social\ThreadsMediaContainerNotFoundException;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * tantra: replies to a Threads channel's own recent posts (threads_read_replies)
 * and a reply posted under one of them (threads_manage_replies).
 */
final class ThreadsReplies
{
    private const string POST_FIELDS = 'id,text,permalink,timestamp,media_type';

    private const string REPLY_FIELDS = 'id,text,username,permalink,timestamp,hide_status';

    /** Meta asks apps to let a reply container finish before publishing it. */
    private const int CONTAINER_WAIT_SECONDS = 30;

    private const int CONTAINER_POLL_SECONDS = 2;

    /** OAuth errors that mean the token is gone or lacks the permission: the channel must reconnect. */
    private const array RECONNECT_CODES = [10, 190, 200];

    /** @return list<array<string, mixed>> */
    public static function recent(SocialAccount $account, int $posts = 5): array
    {
        $base = self::base();
        $token = $account->access_token;

        $list = self::json(Http::timeout(20)->get("{$base}/{$account->platform_user_id}/threads", [
            'fields' => self::POST_FIELDS,
            'limit' => $posts,
            'access_token' => $token,
        ]), 'data');

        // Reposts of someone else's post carry no replies of their own.
        $list = array_values(array_filter($list, fn (array $post): bool => ($post['media_type'] ?? null) !== 'REPOST_FACADE' && filled($post['id'] ?? null)));

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (array $post) => $pool->as((string) $post['id'])->timeout(20)->get("{$base}/{$post['id']}/replies", [
                'fields' => self::REPLY_FIELDS,
                'reverse' => 'false',
                'access_token' => $token,
            ]),
            $list,
        ));

        return array_map(function (array $post) use ($responses): array {
            $response = $responses[(string) $post['id']] ?? null;
            // One post Threads will not list replies for must not blank the panel.
            $replies = $response instanceof Response && $response->successful() ? (array) $response->json('data', []) : [];

            return [
                'id' => (string) $post['id'],
                'text' => $post['text'] ?? null,
                'permalink' => $post['permalink'] ?? null,
                'timestamp' => $post['timestamp'] ?? null,
                'replies_failed' => ! ($response instanceof Response && $response->successful()),
                'replies' => array_values(array_map(fn (array $reply): array => [
                    'id' => (string) $reply['id'],
                    'text' => $reply['text'] ?? null,
                    'username' => $reply['username'] ?? null,
                    'permalink' => $reply['permalink'] ?? null,
                    'timestamp' => $reply['timestamp'] ?? null,
                ], array_filter($replies, fn (array $reply): bool => filled($reply['id'] ?? null) && ($reply['hide_status'] ?? 'NOT_HUSHED') !== 'HIDDEN'))),
            ];
        }, $list);
    }

    /** Whether the id is one of the posts or replies the panel shows for this channel. */
    public static function answerable(SocialAccount $account, string $id): bool
    {
        foreach (self::recent($account) as $post) {
            if ($post['id'] === $id || in_array($id, array_column($post['replies'], 'id'), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{id: string, permalink: ?string} */
    public static function reply(SocialAccount $account, string $replyToId, string $text): array
    {
        $base = self::base();
        $userId = $account->platform_user_id;
        $token = $account->access_token;

        for ($attempt = 1; ; $attempt++) {
            $container = (string) self::json(Http::asForm()->timeout(20)->post("{$base}/{$userId}/threads", [
                'media_type' => 'TEXT',
                'text' => $text,
                'reply_to_id' => $replyToId,
                'access_token' => $token,
            ]), 'id');

            if ($container === '') {
                throw new RuntimeException('Threads returned no reply container.');
            }

            self::waitUntilFinished($container, $token);

            $response = Http::asForm()->timeout(20)->post("{$base}/{$userId}/threads_publish", [
                'creation_id' => $container,
                'access_token' => $token,
            ]);

            // A container Threads has not indexed yet: one fresh attempt.
            if ($attempt < 2 && ThreadsMediaContainerNotFoundException::matches($response)) {
                Sleep::for(3)->seconds();

                continue;
            }

            $id = (string) self::json($response, 'id');

            if ($id === '') {
                throw new RuntimeException('Threads did not publish the reply.');
            }

            $permalink = rescue(fn () => Http::timeout(10)->get("{$base}/{$id}", ['fields' => 'permalink', 'access_token' => $token])->json('permalink'), report: false);

            return ['id' => $id, 'permalink' => is_string($permalink) ? $permalink : null];
        }
    }

    private static function waitUntilFinished(string $container, string $token): void
    {
        $base = self::base();
        $waited = 0;

        while (true) {
            $status = (string) Http::timeout(10)->get("{$base}/{$container}", ['fields' => 'status,error_message', 'access_token' => $token])->json('status');

            if ($status === 'FINISHED' || $status === '') {
                // An empty status (older API answer): publish and let Threads decide.
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException("Threads reply container {$status}.");
            }

            if ($waited >= self::CONTAINER_WAIT_SECONDS) {
                return;
            }

            Sleep::for(self::CONTAINER_POLL_SECONDS)->seconds();
            $waited += self::CONTAINER_POLL_SECONDS;
        }
    }

    /** @return mixed the key's value, after turning auth failures into ThreadsNeedsReconnect */
    private static function json(Response $response, string $key): mixed
    {
        if ($response->failed()) {
            $code = (int) $response->json('error.code');

            if ($response->status() === 401 || in_array($code, self::RECONNECT_CODES, true)) {
                throw new ThreadsNeedsReconnect('Threads refused the token.');
            }

            throw new RequestException($response);
        }

        return $response->json($key, $key === 'data' ? [] : null);
    }

    private static function base(): string
    {
        return (string) config('trypost.platforms.threads.graph_api');
    }
}
