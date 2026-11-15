<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Repurpose\Cadence;
use App\Tantra\Repurpose\Reposts;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * tantra: the Tantra app's Content > Repurpose, server to server (tantra-crm docs/crm/engine-patches/p4-phase3/README.md): turn a post
 * into a version for each channel, through the Engine's own Reposts (the same call as POST /api/tantra/repost). Shared key
 * (EnsureCrmKey); the Tantra team id maps to Engine workspaces, and nothing outside them is listed or used.
 *
 *   POST repurpose/sources   the posts that can be repurposed (the team's review items), newest change first
 *   POST repurpose/targets   the channels a post can go to, by name, and the networks nobody has connected yet
 *   POST repurpose/start     queue one job that writes every version; they land in Review, or in Ideas for an unconnected network
 */
class CrmRepurposeController extends Controller
{
    private const SKIP = [ReviewStatus::Rejected, ReviewStatus::Superseded];

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
            $job = Reposts::start($actor, $source, $data['targets'], $data['note'] ?? null);
        } catch (ValidationException $e) {
            return $this->refuse(422, 'validation', (string) collect($e->errors())->flatten()->first());
        }
        $outcome = $job->toOutcome();

        return response()->json(['data' => $outcome], 201)->header('Cache-Control', 'no-store, private');
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
