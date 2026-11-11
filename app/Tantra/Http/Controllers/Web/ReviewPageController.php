<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewEvent;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** tantra: the review studio pages (PRD 10.2). */
class ReviewPageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspaceIds = self::visibleWorkspaceIds($user);

        $batches = Batch::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->with('workspace:id,name')
            ->withCount([
                'items as waiting_count' => fn ($q) => $q->whereIn('review_status', [ReviewStatus::InReview->value, ReviewStatus::ChangesRequested->value]),
                'items as changes_count' => fn ($q) => $q->where('review_status', ReviewStatus::ChangesRequested->value),
                'items as approved_count' => fn ($q) => $q->where('review_status', ReviewStatus::Approved->value),
                'items as scheduled_count' => fn ($q) => $q->whereIn('review_status', [ReviewStatus::Scheduled->value, ReviewStatus::Published->value]),
                'items as total_count' => fn ($q) => $q->where('review_status', '!=', ReviewStatus::Superseded->value),
            ])
            ->orderByDesc('imported_at')
            ->get()
            ->map(fn (Batch $b): array => [
                'id' => $b->id,
                'engine_batch_id' => $b->engine_batch_id,
                'title' => $b->title ?: 'Batch '.$b->engine_batch_id,
                'dates_label' => $b->dates_label,
                'workspace' => $b->workspace?->name,
                'imported_at' => $b->imported_at?->toIso8601String(),
                'counts' => [
                    'waiting' => $b->waiting_count,
                    'changes' => $b->changes_count,
                    'approved' => $b->approved_count,
                    'scheduled' => $b->scheduled_count,
                    'total' => $b->total_count,
                ],
            ]);

        return Inertia::render('tantra/review/Index', ['batches' => $batches]);
    }

    public function show(Request $request, Batch $batch): Response
    {
        $user = $request->user();
        abort_unless(in_array($batch->workspace_id, self::visibleWorkspaceIds($user)->all(), true), 404);

        $channel = SocialAccount::query()->where('workspace_id', $batch->workspace_id)->orderBy('created_at')->first();
        $items = ReviewItem::query()
            ->where('batch_id', $batch->id)
            ->where('review_status', '!=', ReviewStatus::Superseded->value)
            ->with(['events.user:id,name'])
            ->get()
            ->sortBy(fn (ReviewItem $i): string => ($i->planned_date?->toDateString() ?? '9999').' '.($i->planned_time ?? '').' '.$i->engine_post_id)
            ->values();

        $posts = Post::query()->whereIn('post_group_id', $items->pluck('post_group_id')->filter())->get()->groupBy('post_group_id');

        return Inertia::render('tantra/review/Batch', [
            'batch' => [
                'id' => $batch->id,
                'title' => $batch->title ?: 'Batch '.$batch->engine_batch_id,
                'engine_batch_id' => $batch->engine_batch_id,
                'dates_label' => $batch->dates_label,
                'workspace' => $batch->workspace?->name,
            ],
            'items' => $items->map(fn (ReviewItem $i): array => self::item($i, $user, $channel, $posts->get($i->post_group_id, new Collection))),
        ]);
    }

    /** @return Collection<int, string> */
    public static function visibleWorkspaceIds(User $user): Collection
    {
        $isOwner = $user->account?->owner_id === $user->id;

        return $isOwner
            ? Workspace::query()->where('account_id', $user->account_id)->pluck('id')
            : $user->workspaces()->pluck('workspaces.id');
    }

    /**
     * The full-quality upload file the reviewer can download: the carousel PDF
     * or the original image. Never a resource PDF; those are DM giveaways.
     *
     * @param  array<string, mixed>  $manifest
     * @param  Collection<int, array<string, mixed>>  $postMedia
     * @return array{url: string, label: string}|null
     */
    private static function download(array $manifest, Collection $postMedia): ?array
    {
        $file = $postMedia->first(fn (array $m): bool => filled($m['url'] ?? null));
        if ($file === null) {
            return null;
        }

        $mime = (string) ($file['mime_type'] ?? '');
        $label = match (true) {
            $mime === 'application/pdf' => 'Download PDF'.(isset($manifest['pages']) ? ' · '.$manifest['pages'].' slides' : ''),
            str_starts_with($mime, 'video/') => 'Download video',
            default => 'Download image',
        };

        return ['url' => (string) $file['url'], 'label' => $label];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<string, mixed>
     */
    private static function item(ReviewItem $i, User $user, ?SocialAccount $channel, Collection $posts): array
    {
        $p = $i->payload ?? [];
        $manifest = $i->media_manifest ?? [];
        $postMedia = collect($posts->first()?->media ?? []);
        $previews = collect($manifest['previews'] ?? [])->map(fn (string $path): string => Storage::url($path))->values();
        $images = $postMedia->filter(fn (array $m): bool => str_starts_with((string) ($m['mime_type'] ?? ''), 'image/'))->pluck('url')->filter()->values();
        $video = $postMedia->first(fn (array $m): bool => str_starts_with((string) ($m['mime_type'] ?? ''), 'video/'));
        $scheduled = $posts->pluck('scheduled_at')->filter()->sort()->first();
        $card = (array) ($p['profile_card'] ?? []);

        return [
            'id' => $i->id,
            'engine_post_id' => $i->engine_post_id,
            'platform' => $i->platform,
            'review_status' => $i->review_status->value,
            'revision' => $i->revision,
            'assisted_manual' => $i->assisted_manual,
            'planned' => ['date' => $i->planned_date?->toDateString(), 'time' => $i->planned_time, 'tz' => $i->timezone],
            'scheduled_at' => $scheduled?->toIso8601String(),
            'post_url' => $posts->pluck('platform_url')->filter()->first(),
            'post_id' => $posts->first()?->id,
            'slot_fallback' => $i->slot_fallback,
            'format' => $i->format,
            'pillar' => $i->pillar,
            'short' => $i->short,
            'idea' => $i->idea,
            'sample' => (array) ($p['sample'] ?? []),
            'copy_basis' => $i->copy_basis,
            'sources' => $i->sources ?? [],
            'flags' => $i->flags ?? [],
            'numbers_for_signoff' => collect($i->numbers_for_signoff ?? [])->values()->map(fn (array $n, int $k): array => [
                ...$n,
                'index' => $k,
                'can_sign' => blank($n['signed_at'] ?? null) && ReviewGate::canSign($user, $i, (string) $n['signer']),
            ]),
            'cta' => $p['cta'] ?? null,
            'cta_date' => $i->cta_date?->toDateString(),
            'text' => (string) ($p['text'] ?? $posts->first()?->content ?? ''),
            'first_comment' => $p['first_comment'] ?? null,
            'thread' => array_values((array) ($p['thread'] ?? [])),
            'visual_copy' => array_values((array) ($p['visual_copy'] ?? [])),
            'resource' => $p['resource'] ?? null,
            'team_comments' => collect((array) ($p['engagement_comments'] ?? $p['team_comments'] ?? []))->filter(fn ($c): bool => is_array($c) && filled($c['text'] ?? null))->map(fn (array $c): array => [
                'by' => (string) ($c['by'] ?? ''),
                'name' => $c['name'] ?? null,
                'headline' => $c['headline'] ?? null,
                'avatar' => $c['avatar'] ?? null,
                'text' => (string) $c['text'],
                'reply_to' => isset($c['reply_to']) ? (int) $c['reply_to'] : null,
                'tone' => $c['tone'] ?? null,
            ])->values(),
            'author_key' => (string) ($p['profile'] ?? ''),
            'mentions' => array_values(array_filter((array) ($p['mentions'] ?? []), 'is_string')),
            'media' => [
                'type' => $manifest['type'] ?? ($video ? 'video' : ($images->isNotEmpty() ? 'image' : null)),
                'title' => $manifest['title'] ?? null,
                'files' => $previews->isNotEmpty() ? $previews : $images,
                'video' => $video['url'] ?? null,
                'poll' => $manifest['poll'] ?? null,
                'placement' => $manifest['placement'] ?? null,
                'tall' => (bool) ($manifest['tall'] ?? false),
                'download' => self::download($manifest, $postMedia),
            ],
            'profile' => [
                'name' => (string) ($card['name'] ?? $channel?->display_name ?? $i->workspace?->name ?? ''),
                'headline' => (string) ($card['headline'] ?? ''),
                'avatar' => $card['avatar'] ?? $channel?->avatar_url ?? null,
                'kind' => ($card['kind'] ?? null) === 'company' ? 'company' : 'person',
                'followers_line' => $card['followers_line'] ?? null,
                'x_handle' => $card['x_handle'] ?? null,
            ],
            'can_review' => ReviewGate::isReviewOwner($user, $i),
            'events' => $i->events->map(fn (ReviewEvent $e): array => [
                'kind' => $e->kind->value,
                'body' => $e->body,
                'by' => $e->user?->name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
