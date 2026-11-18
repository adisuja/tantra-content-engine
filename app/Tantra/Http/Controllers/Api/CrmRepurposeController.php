<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Dto\SourceMedia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Repurpose\Cadence;
use App\Tantra\Repurpose\Reposts;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Http\JsonResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * tantra: the Tantra app's Content > Repurpose, server to server (tantra-crm docs/crm/engine-patches/p4-phase3/README.md): turn a post
 * into a version for each channel, through the Engine's own Reposts (the same call as POST /api/tantra/repost). Shared key
 * (EnsureCrmKey); the Tantra team id maps to Engine workspaces, and nothing outside them is listed or used.
 *
 *   POST repurpose/sources   the posts that can be repurposed (the team's review items), newest change first
 *   POST repurpose/targets   the channels a post can go to, by name, and the networks nobody has connected yet
 *   POST repurpose/start     queue one job that writes every version; they land in Review, or in Ideas for an unconnected network
 *
 * For the Tantra app's Repurpose automation (tantra-crm docs/crm/requests/ENGINE_REQUEST_2026_11_18_REPURPOSE.md); every output stays
 * a draft in Review, none of these publish, approve or schedule:
 *   POST repurpose/source-posts  the recent published posts of one of the team's channels, newest first
 *   POST repurpose/start-post    the same job as start, for a post that is not a review item (a feed post, a profile's own post)
 *   POST repurpose/runs          how the jobs are doing, and the versions each made
 */
class CrmRepurposeController extends Controller
{
    private const SKIP = [ReviewStatus::Rejected, ReviewStatus::Superseded];

    /** The automation's full brief (in place of note when given), where the call came from, and the draft only guard. */
    private const BRIEF_RULES = [
        'brief' => ['nullable', 'string', 'max:4000'],
        'origin' => ['nullable', 'string', 'max:40'],
        'draft_only' => ['sometimes', 'boolean'],
    ];

    /** The jobs' states as the Tantra app reads them. */
    private const RUN_STATUS = ['waiting' => 'waiting', 'working' => 'working', 'done' => 'done', 'failed' => 'failed', 'cancelled' => 'failed'];

    public function sources(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $items = $ids === [] ? collect() : ReviewItem::query()->whereIn('workspace_id', $ids)->whereNotIn('review_status', self::SKIP)->orderByDesc('updated_at')->limit(300)->get();
        $q = mb_strtolower(trim((string) ($data['q'] ?? '')));
        $rows = $items->map(fn (ReviewItem $i): array => [
            'id' => $i->id,
            'platform' => $i->platform,
            'network' => Reposts::NETWORK_OF[$i->platform] ?? $i->platform,
            'status' => $i->review_status->value,
            'text' => mb_substr((string) ($i->payload['text'] ?? ''), 0, 3000),
            'planned_date' => $i->planned_date?->toDateString(),
            'updated_at' => $i->updated_at?->utc()->toIso8601String(),
        ])->filter(fn (array $r): bool => $r['text'] !== '' && ($q === '' || str_contains(mb_strtolower($r['text']), $q)))->values();
        $perPage = (int) ($data['per_page'] ?? 50);
        $page = (int) ($data['page'] ?? 1);

        return response()->json([
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function targets(Request $request): JsonResponse
    {
        $data = $request->validate(['workspace' => ['required', 'string', 'max:100'], 'actor_email' => ['required', 'email', 'max:255']]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $actor = $this->actorIn($ids, $data['actor_email']);
        if ($actor === null) {
            return $this->refuse(403, 'unknown_person');
        }
        $all = Reposts::targets($actor);
        $channels = collect($all['channels'])->filter(fn (array $c): bool => in_array($c['workspace_id'], $ids, true));
        $have = $channels->pluck('network')->unique();

        return response()->json(['data' => [
            'channels' => $channels->map(fn (array $c): array => ['id' => $c['id'], 'name' => (string) $c['name'], 'network' => $c['network'], 'platform' => $c['platform'], 'workspace' => $c['workspace'], 'connected' => (bool) $c['connected']])->values(),
            'unconnected' => collect(Cadence::NETWORKS)->reject(fn (string $n): bool => $have->contains($n))->values(),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
            'review_item_id' => ['required', 'uuid'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*.channel_id' => ['nullable', 'uuid'],
            'targets.*.network' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...self::BRIEF_RULES,
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $source = $ids === [] ? null : ReviewItem::query()->whereIn('workspace_id', $ids)->whereNotIn('review_status', self::SKIP)->find($data['review_item_id']);
        if ($source === null) {
            return $this->refuse(404, 'not_found');
        }
        $actor = CrmContent::actor(Workspace::query()->findOrFail($source->workspace_id), $data['actor_email']);
        if ($actor === null) {
            return $this->refuse(403, 'unknown_person');
        }
        // Only channels of this team: a channel id from anywhere else is not a target.
        $allowed = collect(Reposts::targets($actor)['channels'])->filter(fn (array $c): bool => in_array($c['workspace_id'], $ids, true))->pluck('id');
        foreach ($data['targets'] as $target) {
            if (filled($target['channel_id'] ?? null) && ! $allowed->contains($target['channel_id'])) {
                return $this->refuse(404, 'unknown_channel');
            }
        }

        try {
            $job = Reposts::start($actor, $source, $data['targets'], self::note($data), extra: self::extra($data));
        } catch (ValidationException $e) {
            return $this->refuse(422, 'validation', (string) collect($e->errors())->flatten()->first());
        }
        $outcome = $job->toOutcome();

        return response()->json(['data' => $outcome], 201)->header('Cache-Control', 'no-store, private');
    }

    public function sourcePosts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
            'channel_id' => ['required', 'uuid'],
            'since' => ['nullable', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $channel = $ids === [] ? null : SocialAccount::query()->whereIn('workspace_id', $ids)->with('workspace')->find($data['channel_id']);
        if ($channel === null || $channel->workspace === null) {
            return $this->refuse(404, 'unknown_channel');
        }
        if (CrmContent::actor($channel->workspace, $data['actor_email']) === null) {
            return $this->refuse(403, 'unknown_person');
        }
        $since = filled($data['since'] ?? null) ? CarbonImmutable::parse($data['since'])->utc() : now()->subDays(30)->toImmutable();
        $limit = (int) ($data['limit'] ?? 20);

        // The channel's own posts published through the Engine.
        $rows = Post::query()->where('social_account_id', $channel->id)->where('status', PostStatus::Published)
            ->where('published_at', '>', $since)->orderByDesc('published_at')->limit($limit)->get()
            ->map(fn (Post $p): array => [
                'id' => 'post:'.$p->id,
                'text' => mb_substr(trim(strip_tags((string) $p->content)), 0, 6000),
                'url' => filled($p->platform_url) ? (string) $p->platform_url : null,
                'published_at' => $p->published_at?->utc()->toIso8601String(),
            ]);

        // Facebook and Instagram: also the account's own feed, as the Engine's repurpose sources read it.
        if (in_array($channel->platform, SourceFetcherFactory::supportedPlatforms(), true)) {
            try {
                $known = $rows->pluck('url')->filter()->all();
                $feed = collect(app(SourceFetcherFactory::class)->for($channel)->fetch($channel, $since, SourceFormat::cases()))
                    ->filter(fn (SourceMedia $m): bool => trim($m->caption) !== '' && ! $m->predates($since) && ! in_array($m->permalink, $known, true))
                    ->map(fn (SourceMedia $m): array => [
                        'id' => 'media:'.$m->id,
                        'text' => mb_substr(trim($m->caption), 0, 6000),
                        'url' => $m->permalink,
                        'published_at' => $m->createdAt?->utc()->toIso8601String(),
                    ]);
                $rows = $rows->concat($feed);
            } catch (Throwable $e) {
                Log::info('tantra: repurpose source posts could not read the feed', ['platform' => $channel->platform->value, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        $rows = $rows->filter(fn (array $r): bool => $r['text'] !== '')->sortByDesc(fn (array $r): string => (string) $r['published_at'])->take($limit)->values();

        return response()->json(['data' => $rows])->header('Cache-Control', 'no-store, private');
    }

    public function startPost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*.channel_id' => ['nullable', 'uuid'],
            'targets.*.network' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...self::BRIEF_RULES,
            'source' => ['required', 'array'],
            'source.key' => ['required', 'string', 'max:300'],
            'source.text' => ['required', 'string', 'max:6000'],
            'source.url' => ['nullable', 'string', 'url', 'max:1024'],
            'source.published_at' => ['nullable', 'date'],
            'source.network' => ['nullable', 'string', 'max:32'],
            'source.channel_id' => ['nullable', 'uuid'],
            'source.kind' => ['required', 'in:feed,channel'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        if ($ids === []) {
            return $this->refuse(404, 'not_found');
        }
        $source = $data['source'];
        $from = null;
        if (filled($source['channel_id'] ?? null)) {
            $from = SocialAccount::query()->whereIn('workspace_id', $ids)->find($source['channel_id']);
            if ($from === null) {
                return $this->refuse(404, 'unknown_channel');
            }
        }
        // The job belongs to the source profile's workspace, else the first channel picked, else the team's first workspace.
        $firstTarget = collect($data['targets'])->pluck('channel_id')->filter()->first();
        $workspaceId = $from?->workspace_id
            ?? ($firstTarget ? SocialAccount::query()->whereIn('workspace_id', $ids)->whereKey($firstTarget)->value('workspace_id') : null)
            ?? $ids[0];
        $actor = CrmContent::actor(Workspace::query()->findOrFail($workspaceId), $data['actor_email']);
        if ($actor === null) {
            return $this->refuse(403, 'unknown_person');
        }
        $allowed = collect(Reposts::targets($actor)['channels'])->filter(fn (array $c): bool => in_array($c['workspace_id'], $ids, true))->pluck('id');
        foreach ($data['targets'] as $target) {
            if (filled($target['channel_id'] ?? null) && ! $allowed->contains($target['channel_id'])) {
                return $this->refuse(404, 'unknown_channel');
            }
        }
        $source['network'] = $source['network'] ?? ($from !== null ? Reposts::networkOf($from->platform) : null);

        try {
            $job = Reposts::startFromPost($actor, $workspaceId, $source, $data['targets'], self::note($data), self::extra($data));
        } catch (ValidationException $e) {
            return $this->refuse(422, 'validation', (string) collect($e->errors())->flatten()->first());
        }

        return response()->json(['data' => $job->toOutcome()], 201)->header('Cache-Control', 'no-store, private');
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'jobs' => ['required', 'array', 'min:1', 'max:50'],
            'jobs.*' => ['string', 'max:64'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $wanted = collect($data['jobs'])->filter(fn (string $id): bool => Str::isUuid($id))->unique()->values();
        $jobs = $ids === [] || $wanted->isEmpty() ? collect() : AgentJob::query()->whereIn('workspace_id', $ids)->whereIn('id', $wanted)->get();
        $metas = $jobs->isEmpty() ? collect() : IdeaMeta::query()->whereIn('agent_job_id', $jobs->pluck('id'))->with('reviewItem')->get()->groupBy('agent_job_id');

        return response()->json(['data' => $jobs->map(fn (AgentJob $job): array => [
            'id' => $job->id,
            'status' => self::RUN_STATUS[$job->toOutcome()['status']] ?? 'working',
            'items' => collect($metas->get($job->id, []))->sortBy('created_at')->map(fn (IdeaMeta $m): array => [
                'network' => Reposts::NETWORK_OF[$m->platform] ?? ($m->platform === 'linkedin_page' ? 'linkedin' : $m->platform),
                'text' => mb_substr((string) ($m->reviewItem?->payload['text'] ?? data_get($m->preview, 'text', '')), 0, 3000),
                'status' => $m->reviewItem?->review_status->value ?? 'idea',
            ])->values(),
        ])->values()])->header('Cache-Control', 'no-store, private');
    }

    /** @param  array<string, mixed>  $data */
    private static function note(array $data): ?string
    {
        return filled($data['brief'] ?? null) ? (string) $data['brief'] : ($data['note'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{origin: string|null, draft_only: bool}
     */
    private static function extra(array $data): array
    {
        return ['origin' => $data['origin'] ?? null, 'draft_only' => (bool) ($data['draft_only'] ?? false)];
    }

    /** @param  list<string>  $workspaceIds */
    private function actorIn(array $workspaceIds, string $email): ?User
    {
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
            if (($user = CrmContent::actor($workspace, $email)) !== null) {
                return $user;
            }
        }

        return null;
    }

    private function refuse(int $status, string $code, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['error' => $code, 'message' => $message], fn ($v): bool => $v !== null), $status);
    }
}
