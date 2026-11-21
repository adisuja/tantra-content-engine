<?php

declare(strict_types=1);

namespace App\Tantra\Notify\Mirror;

use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Crm\CrmPostPresenter;
use App\Tantra\Repurpose\Reposts;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * tantra: Speed P3. Sends one engine.content_changed event to the CRM intake (POST {crm}/intake/engine, the contract in tantra-crm
 * src/content/mirrorEvents.ts). It takes the next version FIRST and reads the thing's current state AFTER, so whichever job runs last
 * for a post, the highest version always carries a state at least as new as any lower one.
 *
 * A 4xx answer (the CRM refused it: unknown team, bad event) is final and never retried; a 5xx, 429 or a network error is retried. Nothing
 * is mailed on failure: the CRM's reconcile (every 10 minutes for teams with Content open) covers a dropped event.
 */
final class SendContentChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    /** @param array<string, mixed> $hint */
    public function __construct(
        public readonly string $team,
        public readonly string $workspaceId,
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $subjectId,
        public readonly array $hint = [],
    ) {}

    public function handle(): void
    {
        if (! ContentChange::enabled()) {
            return;
        }
        $body = $this->payload();
        if ($body === null) {
            return;
        }

        $response = Http::timeout(max(1, (int) config('tantra.crm.mirror.timeout', 10)))->acceptJson()
            ->withToken((string) config('tantra.crm.intake_key'))
            ->post(rtrim((string) config('tantra.crm.url'), '/').'/intake/engine', $body);

        if ($response->successful()) {
            return;
        }
        $status = $response->status();
        if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
            // Refused: sending it again would be refused again.
            $this->fail(new RuntimeException("The CRM refused {$this->kind} (HTTP {$status})."));

            return;
        }

        throw new RuntimeException("The CRM did not take {$this->kind} (HTTP {$status}).");
    }

    /**
     * The event as the CRM takes it. Null when there is nothing to say (a review item of another workspace, say).
     *
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $kind = $this->kind;
        $ref = $this->subjectId;
        $data = [];

        switch ($this->subject) {
            case ContentChange::SUBJECT_POST:
                // Version first, then the read (see the class note).
                $version = Versions::next("post:{$ref}", $this->floorOf(Post::query()->withoutGlobalScopes()->whereKey($ref)->value('updated_at')));
                $post = Post::query()->with('labels')->find($ref);
                if ($post === null || $post->workspace_id !== $this->workspaceId) {
                    $kind = ContentChange::POST_DELETED;
                    $data = ['removed' => true];
                } else {
                    if ($kind === ContentChange::POST_DELETED) {
                        // Deleted and then restored before this ran: say what it is now.
                        $kind = ContentChange::POST_UPDATED;
                    }
                    $data = ['post' => CrmPostPresenter::present($post)];
                }
                break;

            case ContentChange::SUBJECT_REVIEW:
                $version = Versions::next("review:{$ref}", $this->floorOf(ReviewItem::query()->whereKey($ref)->value('updated_at')));
                $item = ReviewItem::query()->find($ref);
                if ($item !== null && $item->workspace_id !== $this->workspaceId) {
                    return null;
                }
                $data = ['review' => $item === null
                    ? ['ref' => $ref, 'removed' => true]
                    : ['ref' => $item->id, 'status' => $item->review_status?->value, 'revision' => (int) $item->revision, 'post_group_id' => $item->post_group_id]];
                break;

            case ContentChange::SUBJECT_CHANNEL:
                $version = Versions::next("channel:{$ref}", $this->floorOf(SocialAccount::query()->withoutGlobalScopes()->whereKey($ref)->value('updated_at')));
                $account = SocialAccount::query()->withoutGlobalScopes()->with('workspace')->find($ref);
                if ($account === null || $account->workspace_id !== $this->workspaceId) {
                    $kind = ContentChange::CHANNEL_DISCONNECTED;
                    $data = ['removed' => true];
                } else {
                    $kind = $account->status === AccountStatus::Connected ? ContentChange::CHANNEL_CONNECTED : ContentChange::CHANNEL_DISCONNECTED;
                    $data = ['channel' => self::channel($account)];
                }
                break;

            case ContentChange::SUBJECT_METRICS:
                $version = Versions::next("metrics:{$this->workspaceId}", (int) floor(microtime(true) * 1000));
                $ref = 'insights';
                break;

            default:
                return null;
        }

        return [
            'id' => (string) Str::uuid(),
            'event' => ContentChange::EVENT,
            'occurred_at' => now()->toIso8601String(),
            'tantra_team' => $this->team,
            'kind' => $kind,
            'ref' => $ref,
            'version' => $version,
            'data' => $data,
        ];
    }

    /**
     * One channel as the CRM's channels list holds it (the same shape as GET crm/publish/channels).
     *
     * @return array<string, mixed>
     */
    public static function channel(SocialAccount $c): array
    {
        return [
            'id' => $c->id,
            'name' => (string) ($c->display_name ?: $c->username),
            'username' => $c->username,
            'platform' => $c->platform->value,
            'network' => Reposts::NETWORK_OF[$c->platform->value] ?? $c->platform->value,
            'workspace' => $c->workspace?->name,
            'connected' => $c->status === AccountStatus::Connected,
        ];
    }

    private function floorOf(mixed $updatedAt): int
    {
        if ($updatedAt === null) {
            return (int) floor(microtime(true) * 1000);
        }

        return (int) Carbon::parse($updatedAt)->getTimestampMs();
    }
}
