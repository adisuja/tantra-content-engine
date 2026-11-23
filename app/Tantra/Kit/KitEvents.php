<?php

declare(strict_types=1);

namespace App\Tantra\Kit;

use App\Models\User;
use App\Tantra\Boost\BoostAction;
use App\Tantra\Notify\Mirror\TeamOf;
use App\Tantra\Notify\Notify;
use App\Tantra\Notify\SendToCrm;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * tantra: One Content app PRD 3.8. The two kit events the CRM intake takes (POST {crm}/intake/engine, through SendToCrm like every
 * engine.* event) to refresh its cached kit (cm_post_kits):
 *
 *   engine.thread_updated  the kit changed (an edit through the gateway). data = {notify: false, tantra_team, ref, review_item_id,
 *                          etag, version, kit}
 *   engine.boost_action    the boost runner acted on one entry (posted, failed or skipped). data = {notify: false, tantra_team, ref,
 *                          review_item_id, post_id, etag, version, action: {id, kind, entry_index, member, status, reason,
 *                          posted_at}, kit}
 *
 * ref is the review item id (the CRM's follow-up ref). kit is PostKit::present(). Both are sent after commit, only when the CRM is
 * connected and the workspace maps to exactly one Tantra team (TeamOf). The event id is derived from what happened, so a retry is
 * stored once. The recipient is the person who acted, else the workspace owner (the intake needs one); data.notify is false, so no
 * notification starts.
 */
final class KitEvents
{
    public const string THREAD_UPDATED = 'engine.thread_updated';

    public const string BOOST_ACTION = 'engine.boost_action';

    public static function threadUpdated(ReviewItem $item, ?User $by = null): void
    {
        self::send($item, $by?->id, self::THREAD_UPDATED, fn (string $etag): string => "thread_updated:{$item->id}:{$etag}", fn (): array => []);
    }

    /**
     * One Content app PRD 4.2 (critic M9): the CRM starts a post's follow-ups on engine.post_approved, from every approval path. Tantra's
     * own Review (the CRM's Review, the gateway, the API and MCP all approve through ReviewActions) sends it here, apart from the
     * upstream approval email event (that one has data.for_mail = true). Unapprove and reject say so too, so the CRM can pause.
     * data = {notify: false, tantra_team, ref, review_item_id, decision: approved|unapproved|rejected, review_status, post_ids,
     * approved_at, by_member, etag, version, kit}
     */
    public static function decision(ReviewItem $item, ?User $by, string $decision): void
    {
        $event = self::DECISIONS[$decision] ?? null;
        if ($event === null) {
            return;
        }
        $at = (string) ($item->updated_at?->getTimestampMs() ?? floor(microtime(true) * 1000));

        self::send($item, $by?->id, $event, fn (): string => "review_{$decision}:{$item->id}:{$at}", fn (): array => [
            'decision' => $decision,
            'review_status' => $item->review_status?->value,
            'post_ids' => $item->posts()->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            'approved_at' => $item->approved_at?->toIso8601ZuluString(),
            'by_member' => $by?->clerk_user_id,
        ]);
    }

    /** decision => event name. */
    public const array DECISIONS = [
        'approved' => 'engine.post_approved',
        'unapproved' => 'engine.post_unapproved',
        'rejected' => 'engine.post_rejected',
    ];

    public static function boostAction(BoostAction $action): void
    {
        if ($action->kind === 'reaction') {
            return;
        }
        $action->loadMissing('run.item', 'run.post');
        $item = $action->run?->item;
        if ($item === null) {
            return;
        }

        self::send($item, $action->user_id, self::BOOST_ACTION, fn (): string => "boost_action:{$action->id}:{$action->status}", fn (): array => [
            'post_id' => (string) $action->run->post_id,
            'action' => [
                'id' => (string) $action->id,
                'kind' => $action->kind,
                'entry_index' => $action->entry_index,
                'member' => $action->user_id ? User::query()->whereKey($action->user_id)->value('clerk_user_id') : null,
                'status' => $action->status,
                'reason' => $action->status === 'posted' ? null : $action->error,
                'posted_at' => $action->posted_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * @param  callable(string): string  $idOf
     * @param  callable(): array<string, mixed>  $extra
     */
    private static function send(ReviewItem $item, ?string $userId, string $event, callable $idOf, callable $extra): void
    {
        if (! Notify::crmConfigured()) {
            return;
        }
        rescue(function () use ($item, $userId, $event, $idOf, $extra): void {
            $team = TeamOf::workspace($item->workspace_id);
            if ($team === null) {
                return;
            }
            DB::afterCommit(fn () => rescue(function () use ($item, $userId, $event, $idOf, $extra, $team): void {
                $item->loadMissing('workspace');
                $recipient = $userId ?? $item->workspace?->user_id ?? $item->approved_by;
                if ($recipient === null) {
                    return;
                }
                $kit = PostKit::present($item);
                $data = [
                    'notify' => false,
                    'tantra_team' => $team,
                    'ref' => (string) $item->id,
                    'review_item_id' => (string) $item->id,
                    ...$extra(),
                    'etag' => $kit['etag'],
                    'version' => (int) floor(microtime(true) * 1000),
                    'kit' => $kit,
                ];
                SendToCrm::dispatch(
                    Uuid::uuid5(Uuid::NAMESPACE_URL, 'tantra-engine:'.$idOf($kit['etag']))->toString(),
                    $event,
                    (string) $recipient,
                    $data,
                    null,
                    $item->workspace?->name,
                    null,
                    null,
                );
            }, report: false));
        }, report: false);
    }
}
