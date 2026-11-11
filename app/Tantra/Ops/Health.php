<?php

declare(strict_types=1);

namespace App\Tantra\Ops;

use App\Enums\SocialAccount\Platform;
use App\Tantra\Boost\Boost;
use App\Tantra\Unipile\UnipileClient;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * tantra: one health report for the pingers (PRD 8.5): queue depth, the last
 * scheduler tick, failed jobs in 24 hours, and whether Zernio and Unipile
 * answer (asked at most every 10 minutes, so pinging costs them nothing).
 */
final class Health
{
    public const TICK_KEY = 'tantra:scheduler:tick';

    /** The scheduler runs every minute; more than this many minutes without a tick is a failure. */
    public const TICK_LATE_MINUTES = 5;

    public const QUEUE_TOO_DEEP = 500;

    public static function tick(): void
    {
        Cache::forever(self::TICK_KEY, now()->toIso8601ZuluString());
    }

    /** @return array{ok: bool, problems: list<string>, checked_at: string, queues: array<string, int|null>, scheduler_tick: ?string, failed_jobs_24h: int|null, zernio: ?bool, unipile: ?bool} */
    public static function report(): array
    {
        $problems = [];

        $queues = [];
        foreach (array_unique(['default', Boost::QUEUE, ...Platform::enabledQueues()]) as $queue) {
            try {
                $queues[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $queues[$queue] = null;
                $problems[] = "queue {$queue} unreadable";
            }
        }
        if (array_sum(array_filter($queues, 'is_int')) > self::QUEUE_TOO_DEEP) {
            $problems[] = 'queues are backing up';
        }

        $tick = Cache::get(self::TICK_KEY);
        if ($tick === null || now()->diffInMinutes($tick, true) > self::TICK_LATE_MINUTES) {
            $problems[] = 'the scheduler has not run in the last '.self::TICK_LATE_MINUTES.' minutes';
        }

        try {
            $failed = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            $failed = null;
        }

        $zernio = ZernioClient::configured() ? Cache::remember('tantra:health:zernio', 600, fn (): bool => self::answers(fn () => app(ZernioClient::class)->accounts())) : null;
        $unipile = UnipileClient::configured() ? Cache::remember('tantra:health:unipile', 600, fn (): bool => self::answers(fn () => app(UnipileClient::class)->accounts())) : null;
        if ($zernio === false) {
            $problems[] = 'Zernio does not answer';
        }
        if ($unipile === false) {
            $problems[] = 'Unipile does not answer';
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'checked_at' => now()->toIso8601ZuluString(),
            'queues' => $queues,
            'scheduler_tick' => $tick,
            'failed_jobs_24h' => $failed,
            'zernio' => $zernio,
            'unipile' => $unipile,
        ];
    }

    private static function answers(callable $call): bool
    {
        try {
            $call();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
