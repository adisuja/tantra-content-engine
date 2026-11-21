<?php

declare(strict_types=1);

namespace App\Tantra\Notify\Mirror;

use App\Tantra\Notify\Notify;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * tantra: Speed P3 (tantra-crm PRD_2026_11_21_SPEED_AND_STABILITY section 10, B1 to B3). Every change to what Tantra's Content screens show
 * goes to the CRM intake as one engine.content_changed event, so the team's mirror (cm_* tables) stays current within seconds.
 *
 * - Sent AFTER COMMIT (a rolled back save sends nothing), on its own low priority queue (config tantra.crm.mirror.queue, Horizon
 *   supervisor tantra-mirror), never the publishing workers.
 * - Only for a workspace mapped to a Tantra team (TeamOf): no mapping, nothing is queued at all.
 * - The job reads the thing's CURRENT state when it runs, after taking the next version (Versions), so a late job can never put an
 *   older state over a newer one in the CRM: the CRM applies a row only when its version is newer.
 */
final class ContentChange
{
    public const string EVENT = 'engine.content_changed';

    public const string POST_CREATED = 'post.created';

    public const string POST_UPDATED = 'post.updated';

    public const string POST_SCHEDULED = 'post.scheduled';

    public const string POST_PUBLISHED = 'post.published';

    public const string POST_FAILED = 'post.failed';

    public const string POST_DELETED = 'post.deleted';

    public const string REVIEW_CHANGED = 'review.changed';

    public const string CHANNEL_CONNECTED = 'channel.connected';

    public const string CHANNEL_DISCONNECTED = 'channel.disconnected';

    public const string METRICS_READ = 'metrics.read';

    public const array KINDS = [
        self::POST_CREATED, self::POST_UPDATED, self::POST_SCHEDULED, self::POST_PUBLISHED, self::POST_FAILED, self::POST_DELETED,
        self::REVIEW_CHANGED, self::CHANNEL_CONNECTED, self::CHANNEL_DISCONNECTED, self::METRICS_READ,
    ];

    /** What the job reads again when it runs. */
    public const string SUBJECT_POST = 'post';

    public const string SUBJECT_REVIEW = 'review';

    public const string SUBJECT_CHANNEL = 'channel';

    public const string SUBJECT_METRICS = 'metrics';

    public static function enabled(): bool
    {
        return (bool) config('tantra.crm.mirror.enabled') && Notify::crmConfigured();
    }

    public static function queue(): string
    {
        return (string) (config('tantra.crm.mirror.queue') ?: 'tantra-mirror');
    }

    /**
     * Queue one change for after the surrounding transaction commits (at once when there is none).
     *
     * @param  array<string, mixed>  $hint  facts the job cannot read again later (the removed thing's platform, say)
     */
    public static function record(?string $workspaceId, string $kind, string $subject, string $subjectId, array $hint = []): void
    {
        if (! self::enabled() || ! in_array($kind, self::KINDS, true) || $subjectId === '') {
            return;
        }
        rescue(function () use ($workspaceId, $kind, $subject, $subjectId, $hint): void {
            $team = TeamOf::workspace($workspaceId);
            if ($team === null) {
                return;
            }
            DB::afterCommit(fn () => rescue(fn () => SendContentChange::dispatch($team, (string) $workspaceId, $kind, $subject, $subjectId, $hint)
                ->onQueue(self::queue())));
        }, report: false);
    }

    /** Metrics come in bursts (a row per post per day): one metrics.read per workspace per debounce window, sent at its end. */
    public static function metricsRead(?string $workspaceId): void
    {
        if (! self::enabled() || blank($workspaceId)) {
            return;
        }
        $window = max(1, (int) config('tantra.crm.mirror.metrics_debounce', 60));
        rescue(function () use ($workspaceId, $window): void {
            $team = TeamOf::workspace($workspaceId);
            if ($team === null || ! Cache::add("tantra-mirror:metrics:{$workspaceId}", 1, $window)) {
                return;
            }
            DB::afterCommit(fn () => rescue(fn () => SendContentChange::dispatch($team, (string) $workspaceId, self::METRICS_READ, self::SUBJECT_METRICS, (string) $workspaceId)
                ->onQueue(self::queue())->delay(now()->addSeconds($window))));
        }, report: false);
    }
}
