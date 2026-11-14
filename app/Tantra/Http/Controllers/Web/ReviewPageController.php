<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Analytics\MetricSnapshot;
use App\Tantra\Boost\ThreadDelivery;
use App\Tantra\Channels\ReconnectReminders;
use App\Tantra\Giveaway\GiveawayReply;
use App\Tantra\LinkedIn\Identity;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Messages;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewEvent;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** tantra: the review studio pages (PRD 10.2). */
class ReviewPageController extends Controller
{
    /**
     * The one review screen (PRD 10.2, redesigned 2026-11-12): one post at a
     * time as its phone preview. Review holds what this user approves (waiting
     * first, then revisions pending); Scheduled what is in a slot; Published
     * what went out, with its numbers. Each post says how every part of it goes
     * out (ThreadDelivery). A batch link from an email (?batch=) shows that batch.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        Identity::flush();
        $tab = in_array($request->query('tab'), ['scheduled', 'published'], true) ? (string) $request->query('tab') : 'review';
        $batch = $request->filled('batch') && Str::isUuid((string) $request->query('batch'))
            ? Batch::query()->whereIn('workspace_id', self::visibleWorkspaceIds($user))->find($request->query('batch'))
            : null;

        // A link to one post (?item=, from Slack or an email): open its batch on the tab
        // that holds it, with that post in front. Posts this person cannot see are ignored.
        $focus = $request->filled('item') && Str::isUuid((string) $request->query('item'))
            ? ReviewItem::query()->where('account_id', $user->account_id)->whereIn('workspace_id', self::visibleWorkspaceIds($user))->find($request->query('item'))
            : null;
        if ($focus !== null) {
            $batch = $focus->batch;
            $tab = match ($focus->review_status) {
                ReviewStatus::Approved, ReviewStatus::Scheduled => 'scheduled',
                ReviewStatus::Published => 'published',
                default => 'review',
            };
        }

        $statuses = [
            'review' => [ReviewStatus::InReview->value, ReviewStatus::ChangesRequested->value],
            'scheduled' => [ReviewStatus::Approved->value, ReviewStatus::Scheduled->value],
            'published' => [ReviewStatus::Published->value],
        ];
        $base = fn () => ReviewItem::query()->where('account_id', $user->account_id)->where('review_status', '!=', ReviewStatus::Superseded->value);
        // Review: only what this person approves. Scheduled and Published: everything they can see.
        $scope = fn (string $t) => $t === 'review'
            ? $base()->whereIn('workspace_id', ReviewGate::reviewedWorkspaceIds($user))
            : $base()->whereIn('workspace_id', self::visibleWorkspaceIds($user));

        $query = ($batch !== null ? $base()->where('batch_id', $batch->id) : $scope($tab))->whereIn('review_status', $statuses[$tab]);

        $items = $query->with(['events.user:id,name', 'workspace'])->limit(200)->get();
        $items = ($tab === 'published'
            ? $items->sortByDesc(fn (ReviewItem $i): string => ($i->planned_date?->toDateString() ?? '').' '.$i->engine_post_id)
            : $items->sortBy(fn (ReviewItem $i): string => ($i->review_status === ReviewStatus::ChangesRequested ? '1' : '0').' '.($i->planned_date?->toDateString() ?? '9999').' '.($i->planned_time ?? '').' '.$i->engine_post_id))
            ->values();

        $channels = SocialAccount::query()->whereIn('workspace_id', $items->pluck('workspace_id')->unique())->orderBy('created_at')->get()->unique('workspace_id')->keyBy('workspace_id');
        $posts = Post::query()->whereIn('post_group_id', $items->pluck('post_group_id')->filter())->get()->groupBy('post_group_id');

        $cache = [];
        $rows = $items->map(function (ReviewItem $i) use ($user, $channels, $posts, &$cache): array {
            $group = $posts->get($i->post_group_id, new Collection);
            $row = self::item($i, $user, $channels->get($i->workspace_id), $group);
            $row['delivery'] = ThreadDelivery::for($i, $group->first(), $channels->get($i->workspace_id), $cache);
            $row['workspace'] = $i->workspace?->name;
            $row['batch_id'] = $i->batch_id;
            $row['published_at'] = $group->pluck('published_at')->filter()->sort()->first()?->toIso8601String();
            $snap = $group->isEmpty() ? null : MetricSnapshot::query()->whereIn('post_id', $group->modelKeys())->latest('captured_at')->first();
            $row['metrics'] = $snap ? ['impressions' => $snap->impressions, 'reactions' => $snap->reactions, 'comments' => $snap->comments, 'shares' => $snap->shares, 'clicks' => $snap->clicks] : null;

            return $row;
        });

        return Inertia::render('tantra/review/Index', [
            'tab' => $tab,
            'items' => $rows,
            'batch' => $batch ? ['id' => $batch->id, 'title' => $batch->title ?: 'Batch '.$batch->engine_batch_id] : null,
            'focus' => $focus?->id,
            'counts' => collect($statuses)->map(fn (array $st, string $t): int => $scope($t)->whereIn('review_status', $st)->count())->all(),
            'reconnect' => ReconnectReminders::forUser($user),
            'composer' => ReviewGate::composerWaiting($user),
        ]);
    }

    /** Old batch links (review emails) open the one review screen on that batch. */
    public function show(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless(in_array($batch->workspace_id, self::visibleWorkspaceIds($request->user())->all(), true), 404);

        return redirect()->route('tantra.review.index', ['batch' => $batch->id]);
    }

    /**
     * The author as LinkedIn (or X) shows them, from Identity. A profile card sent
     * with an older batch fills only what LinkedIn did not give us.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private static function profile(ReviewItem $i, ?SocialAccount $channel, array $card): array
    {
        $network = match (true) {
            $i->platform === 'x' => 'x',
            $channel !== null && in_array($channel->platform, [Platform::ZernioLinkedInPage, Platform::LinkedInPage], true) => 'linkedin_page',
            ($card['kind'] ?? null) === 'company' => 'linkedin_page',
            default => 'linkedin',
        };
        $id = Identity::forWorkspace((string) $i->workspace_id, $network) ?? [];

        return [
            'name' => (string) (($id['name'] ?? '') ?: ($card['name'] ?? $i->workspace?->name ?? '')),
            'headline' => (string) (($id['headline'] ?? '') ?: ($card['headline'] ?? '')),
            'avatar' => ($id['avatar'] ?? null) ?: ($card['avatar'] ?? null),
            'kind' => ($id['kind'] ?? null) ?: (($card['kind'] ?? null) === 'company' ? 'company' : 'person'),
            'followers_line' => ($id['followers_line'] ?? null) ?: ($card['followers_line'] ?? null),
            'x_handle' => ($id['x_handle'] ?? null) ?: ($card['x_handle'] ?? null),
            'profile_url' => $id['profile_url'] ?? null,
        ];
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
        // Before the drafts exist (the teammate has not connected LinkedIn yet) the
        // files wait as held uploads: show those, so a video or image still plays.
        $held = array_values(array_filter((array) ($manifest['held_tokens'] ?? []), 'is_string'));
        if ($postMedia->isEmpty() && $held !== []) {
            $postMedia = Media::query()->where('workspace_id', $i->workspace_id)->whereIn('upload_token', $held)->orderBy('order')->get()
                ->map(fn (Media $m): array => ['url' => $m->url, 'mime_type' => $m->mime_type]);
        }
        $previews = collect($manifest['previews'] ?? [])->map(fn (string $path): string => Storage::url($path))->values();
        $images = $postMedia->filter(fn (array $m): bool => str_starts_with((string) ($m['mime_type'] ?? ''), 'image/'))->pluck('url')->filter()->values();
        $video = $postMedia->first(fn (array $m): bool => str_starts_with((string) ($m['mime_type'] ?? ''), 'video/'));
        $scheduled = $posts->pluck('scheduled_at')->filter()->sort()->first();

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
            'team_comments' => collect($i->thread())->map(fn (array $c): array => [
                'index' => (int) $c['index'],
                'by' => (string) ($c['by'] ?? ''),
                // Who they are comes from LinkedIn (Identity); the batch's names are a last resort.
                'name' => Identity::forKey((string) $i->account_id, (string) ($c['by'] ?? ''))['name'] ?? $c['name'] ?? null,
                'headline' => Identity::forKey((string) $i->account_id, (string) ($c['by'] ?? ''))['headline'] ?? $c['headline'] ?? null,
                'avatar' => Identity::forKey((string) $i->account_id, (string) ($c['by'] ?? ''))['avatar'] ?? $c['avatar'] ?? null,
                'text' => (string) $c['text'],
                'reply_to' => isset($c['reply_to']) ? (int) $c['reply_to'] : null,
                'tone' => $c['tone'] ?? null,
                'edited' => (bool) $c['edited'],
                'excluded' => (bool) $c['excluded'],
            ])->values(),
            'author_key' => (string) ($p['profile'] ?? ''),
            'dm_list' => GiveawayReply::query()->where('review_item_id', $i->id)->where('status', 'replied')->orderBy('replied_at')->get()
                ->map(fn (GiveawayReply $r): array => [
                    'id' => $r->id,
                    'name' => $r->commenter_name ?: (Str::of((string) $r->commenter_url)->rtrim('/')->afterLast('/')->value() ?: null),
                    'url' => $r->commenter_url,
                    'comment' => $r->comment_text,
                    'sent' => $r->dm_sent_at !== null,
                    // The DM as it should go out: the review owner's edited text, their first name filled in.
                    'message' => Messages::render($i, 'linkedin_dm', Str::of((string) $r->commenter_name)->before(' ')->trim()->value(), $posts->pluck('platform_url')->filter()->first()),
                ])->values(),
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
            'profile' => self::profile($i, $channel, (array) ($p['profile_card'] ?? [])),
            'can_review' => ReviewGate::isReviewOwner($user, $i),
            'share_url' => $i->shareUrl(),
            'preview_url' => $i->previewUrl(),
            'resource_pages' => collect((array) ($manifest['resource_previews'] ?? []))->filter(fn ($path): bool => is_string($path))->map(fn (string $path): string => Storage::url($path))->values(),
            'messages' => Messages::for($i),
            'events' => $i->events->map(fn (ReviewEvent $e): array => [
                'kind' => $e->kind->value,
                'body' => $e->body,
                'by' => $e->user?->name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
