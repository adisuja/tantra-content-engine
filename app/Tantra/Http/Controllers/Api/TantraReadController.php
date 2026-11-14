<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Analytics\Insights;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Channels\TeamChannels;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * tantra: the read side of the screens over the API (PRD 25.12), acting as
 * the token's own user with the same visibility as the screens: review items,
 * posts to publish by hand, the team, and Tantra Insights. Actions reuse the
 * screens' own JSON controllers (routes-api.php).
 */
class TantraReadController extends Controller
{
    public function reviewItems(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'], 'workspace_id' => ['nullable', 'uuid'],
            'batch' => ['nullable', 'string', 'max:32'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $user = $request->user();
        $rows = ReviewItem::query()->whereIn('workspace_id', ReviewPageController::visibleWorkspaceIds($user))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->whereIn('review_status', explode(',', $s)))
            ->when($data['workspace_id'] ?? null, fn ($q, $w) => $q->where('workspace_id', $w))
            ->when($data['batch'] ?? null, fn ($q, $b) => $q->where('engine_batch_id', $b))
            ->with('workspace:id,name')->latest('updated_at')->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($rows->items())->map(fn (ReviewItem $i): array => [
                'id' => $i->id, 'engine_batch_id' => $i->engine_batch_id, 'engine_post_id' => $i->engine_post_id,
                'workspace' => $i->workspace?->name, 'workspace_id' => $i->workspace_id, 'platform' => $i->platform,
                'status' => $i->review_status->value, 'revision' => $i->revision,
                'planned' => ['date' => $i->planned_date?->toDateString(), 'time' => $i->planned_time, 'tz' => $i->timezone],
                'text' => Str::limit((string) ($i->payload['text'] ?? ''), 280), 'can_review' => ReviewGate::isReviewOwner($user, $i),
                'review_url' => url('/tantra/review/'.$i->batch_id.'#'.$i->engine_post_id),
            ])->values(),
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = ManualTask::query()->whereIn('workspace_id', ReviewPageController::visibleWorkspaceIds($user))
            ->whereIn('status', array_filter(explode(',', (string) $request->query('status', 'waiting,due'))))
            ->with('workspace:id,name')->orderBy('due_at')->limit(200)->get();

        return response()->json(['data' => $tasks->map(fn (ManualTask $t): array => [
            'id' => $t->id, 'kind' => $t->kind, 'status' => $t->status, 'due_at' => $t->due_at?->toIso8601String(),
            'text' => $t->text, 'thread' => $t->thread ?? [], 'media_url' => $t->media_url, 'workspace' => $t->workspace?->name,
        ])->values()]);
    }

    public function team(Request $request): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user->account?->owner_id === $user->id;
        $members = User::query()->where('account_id', $user->account_id)->orderBy('name')->get()
            ->filter(fn (User $u): bool => $isOwner || $u->id === $user->id);
        $profiles = MemberProfile::query()->whereIn('user_id', $members->pluck('id'))->get()->keyBy('user_id');

        return response()->json(['data' => $members->map(fn (User $u): array => [
            'id' => $u->id, 'name' => $u->name, 'email' => $isOwner || $u->id === $user->id ? $u->email : null,
            'linkedin' => ['name' => $profiles->get($u->id)?->linkedin_name, 'headline' => $profiles->get($u->id)?->linkedin_headline, 'status' => $profiles->get($u->id)?->unipile_status],
            'unipile_matched' => $profiles->get($u->id)?->unipile_confirmed_at !== null,
            'consent' => $profiles->get($u->id)?->boost_consent_at !== null,
            'expertise' => $profiles->get($u->id)?->expertise,
        ])->values()]);
    }

    public function insights(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', '30');
        $days = in_array($days, Insights::RANGES, true) ? $days : 30;

        return response()->json(['data' => Insights::for(ReviewPageController::visibleWorkspaceIds($request->user())->all(), $days)]);
    }

    /** Admins: make or update a channel for every teammate's LinkedIn in Unipile now, not at the next 15 minutes. */
    public function syncChannels(UnipileClient $unipile): JsonResponse
    {
        return response()->json(['data' => TeamChannels::sync($unipile)]);
    }
}
