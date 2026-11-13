<?php

declare(strict_types=1);

namespace App\Tantra\Review\Actions;

use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\HostInlineMedia;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Tantra\Review\Enums\EventKind;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * tantra: imports one engine batch (PRD 10.1). Each engine post becomes a
 * review item in the workspace its profile maps to, plus a TryPost draft per
 * channel (created through upstream's CreatePosts, so validation, media and
 * queue rules stay upstream's). Idempotent on (engine_batch_id, engine_post_id)
 * across the Account: a re-push replaces copy and media while the item is still
 * in review, and leaves approved or scheduled items alone unless force-reopened.
 */
final class ImportBatch
{
    public const LABEL_COLOR = '#89CFF0';

    /**
     * @param  array<string, mixed>  $payload  validated request body
     * @return array{batches: list<array<string, string>>, items: list<array<string, mixed>>}
     */
    public static function execute(User $owner, array $payload): array
    {
        $engineBatchId = (string) $payload['engine_batch_id'];
        $posts = collect($payload['posts']);
        $forceReopen = collect($payload['force_reopen'] ?? [])->map(fn ($id): string => (string) $id)->all();

        $workspaces = self::resolveWorkspaces($owner, $posts);

        $batches = [];
        $results = [];

        // Parents first, so an X version in the same push can link to its LinkedIn item.
        $ordered = $posts->sortBy(fn (array $p): int => filled($p['parent_engine_post_id'] ?? null) ? 1 : 0)->values();

        foreach ($ordered as $post) {
            $workspace = $workspaces[$post['profile']];
            $batch = $batches[$workspace->id] ??= Batch::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'engine_batch_id' => $engineBatchId],
                [
                    'title' => $payload['title'] ?? null,
                    'dates_label' => $payload['dates_label'] ?? null,
                    'source_commit' => $payload['source_commit'] ?? null,
                    'imported_by' => $owner->id,
                    'imported_at' => now(),
                ],
            );

            $results[] = self::importPost($owner, $workspace, $batch, $engineBatchId, $post, in_array((string) $post['engine_post_id'], $forceReopen, true));
        }

        NotifyReviewers::afterImport($results);

        return [
            'batches' => collect($batches)->map(fn (Batch $b): array => ['batch_id' => $b->id, 'workspace_id' => $b->workspace_id])->values()->all(),
            'items' => $results,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array<string, Workspace>
     */
    private static function resolveWorkspaces(User $owner, Collection $posts): array
    {
        $keys = $posts->pluck('profile')->map(fn ($k): string => (string) $k)->unique()->values();

        $settings = WorkspaceSettings::query()
            ->whereIn('brand_profile_key', $keys)
            ->whereHas('workspace', fn ($q) => $q->where('account_id', $owner->account_id))
            ->with('workspace')
            ->get()
            ->keyBy('brand_profile_key');

        $missing = $keys->reject(fn (string $k): bool => $settings->has($k))->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'posts' => 'No workspace is mapped to profile: '.$missing->implode(', ').'. Map it with tantra:map-profile first.',
            ]);
        }

        return $settings->map(fn (WorkspaceSettings $s): Workspace => $s->workspace)->all();
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private static function importPost(User $owner, Workspace $workspace, Batch $batch, string $engineBatchId, array $post, bool $forceReopen): array
    {
        $engineId = (string) $post['engine_post_id'];

        return DB::transaction(function () use ($owner, $workspace, $batch, $engineBatchId, $post, $forceReopen, $engineId): array {
            $item = ReviewItem::query()
                ->where('account_id', $owner->account_id)
                ->where('engine_batch_id', $engineBatchId)
                ->where('engine_post_id', $engineId)
                ->lockForUpdate()
                ->first();

            if ($item !== null && ! $item->review_status->isEditableByEngine() && ! $forceReopen) {
                return self::result($item, 'unchanged', 'locked: '.$item->review_status->value);
            }

            if ($item !== null && $forceReopen && self::hasLivePost($item)) {
                return self::result($item, 'unchanged', 'locked: a channel post is already publishing or published');
            }

            $attributes = self::attributes($workspace, $batch, $engineBatchId, $post);

            if ($item === null) {
                $item = new ReviewItem(['account_id' => $owner->account_id, ...$attributes, 'review_status' => ReviewStatus::InReview, 'revision' => 1]);
                $item->save();
                $outcome = 'created';
            } else {
                foreach ($item->posts() as $old) {
                    DeletePost::execute($old, respectStatus: true);
                }
                $item->fill([...$attributes, 'review_status' => ReviewStatus::InReview, 'post_group_id' => null, 'approved_by' => null, 'approved_at' => null, 'slot_fallback' => false]);
                $item->revision = $item->revision + 1;
                $item->save();
                $outcome = 'updated';
            }

            $channel = $item->assisted_manual ? null : self::channelFor($workspace, (string) $post['platform']);

            if ($channel !== null) {
                $group = self::createDrafts($owner, $workspace, $channel, $batch, $post);
                $item->post_group_id = $group;
                $item->save();
            } elseif (! $item->assisted_manual) {
                // No channel yet (a teammate who has not joined): keep the media
                // safe from the 24-hour upload pruning; the drafts are made when
                // a channel is connected (DraftMaker).
                DraftMaker::hold($workspace, $item, (array) ($post['upload_tokens'] ?? []));
                $item->flags = [...($item->flags ?? []), DraftMaker::NO_CHANNEL_FLAG];
                $item->save();
            }

            $item->record(
                $outcome === 'created' ? EventKind::Imported : EventKind::Revised,
                $owner,
                null,
                ['revision' => $item->revision, 'forced' => $forceReopen, 'source_commit' => $batch->source_commit],
            );

            return self::result($item->refresh(), $outcome);
        });
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private static function attributes(Workspace $workspace, Batch $batch, string $engineBatchId, array $post): array
    {
        $platform = strtolower((string) $post['platform']);
        $media = (array) ($post['media'] ?? []);
        $isPoll = ($media['type'] ?? null) === 'poll';

        $parentId = null;
        if (filled($post['parent_engine_post_id'] ?? null)) {
            $parentId = ReviewItem::query()
                ->where('account_id', $workspace->account_id)
                ->where('engine_batch_id', $engineBatchId)
                ->where('engine_post_id', (string) $post['parent_engine_post_id'])
                ->value('id');
        }

        return [
            'batch_id' => $batch->id,
            'workspace_id' => $workspace->id,
            'engine_batch_id' => $engineBatchId,
            'engine_post_id' => (string) $post['engine_post_id'],
            'parent_review_item_id' => $parentId,
            'platform' => $platform,
            'source' => 'engine',
            // X is posted by hand (no API spend); TryPost's LinkedIn publisher has no polls.
            'assisted_manual' => $platform === 'x' || ($platform === 'linkedin' && $isPoll),
            'planned_date' => $post['date'] ?? null,
            'planned_time' => $post['time'] ?? null,
            'timezone' => $post['tz'] ?? null,
            'format' => $post['format'] ?? null,
            'pillar' => $post['pillar'] ?? null,
            'short' => $post['short'] ?? null,
            'idea' => $post['idea'] ?? null,
            'sample_id' => data_get($post, 'sample.id'),
            'sample_creator' => data_get($post, 'sample.creator'),
            'design_system' => $post['design_system'] ?? null,
            'copy_basis' => $post['copy_basis'] ?? null,
            'numbers_for_signoff' => self::signoffs((array) ($post['numbers_for_signoff'] ?? [])),
            'sources' => array_values((array) ($post['sources'] ?? [])),
            'flags' => array_values((array) ($post['flags'] ?? [])),
            'cta_date' => $post['cta_date'] ?? null,
            'media_manifest' => [
                'type' => $media['type'] ?? null,
                'title' => $media['title'] ?? null,
                'previews' => array_values((array) ($post['preview_paths'] ?? [])),
                'resource_previews' => array_values((array) ($post['resource_preview_paths'] ?? [])),
                'poll' => $isPoll ? Arr::only($media, ['question', 'options', 'duration']) : null,
                'placement' => $media['placement'] ?? null,
                'tall' => (bool) ($media['tall'] ?? false),
            ],
            'payload' => Arr::except($post, ['upload_tokens', 'resource_preview_paths']),
        ];
    }

    /**
     * Plain strings (older batches) are read as owner sign-offs.
     *
     * @param  array<int, mixed>  $numbers
     * @return list<array{text: string, signer: string, signed_by: null, signed_at: null}>
     */
    private static function signoffs(array $numbers): array
    {
        return array_values(array_map(function (mixed $n): array {
            $text = is_array($n) ? (string) ($n['text'] ?? '') : (string) $n;
            $signer = is_array($n) && in_array($n['signer'] ?? null, ['owner', 'adi'], true) ? $n['signer'] : 'owner';

            return ['text' => $text, 'signer' => $signer, 'signed_by' => null, 'signed_at' => null];
        }, $numbers));
    }

    public static function channelFor(Workspace $workspace, string $platform): ?SocialAccount
    {
        $platforms = match (strtolower($platform)) {
            // A personal profile through TryPost or Unipile, the Tantra page through Zernio.
            'linkedin' => [Platform::LinkedIn->value, Platform::UnipileLinkedIn->value, Platform::ZernioLinkedInPage->value, Platform::LinkedInPage->value],
            default => [],
        };

        return $platforms === []
            ? null
            : SocialAccount::query()->where('workspace_id', $workspace->id)->whereIn('platform', $platforms)->orderBy('created_at')->first();
    }

    /** @param array<string, mixed> $post */
    public static function createDrafts(User $owner, Workspace $workspace, SocialAccount $channel, Batch $batch, array $post): string
    {
        $meta = [];
        if (filled($post['first_comment'] ?? null)) {
            $meta['thread_replies'] = [['text' => (string) $post['first_comment']]];
        }
        if (in_array(data_get($post, 'media.type'), ['carousel', 'document', 'pdf'], true) && filled(data_get($post, 'media.title'))) {
            $meta['document_title'] = (string) data_get($post, 'media.title');
        }

        $label = WorkspaceLabel::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'From batch '.$batch->engine_batch_id],
            ['color' => self::LABEL_COLOR],
        );

        // Upload tokens become hosted media items first, the way upstream's
        // POST /api/posts/batch does before CreatePosts.
        $created = CreatePosts::execute($workspace, $owner, HostInlineMedia::forBatch($workspace, [
            'status' => PostStatus::Draft->value,
            'content' => (string) ($post['text'] ?? ''),
            'media' => array_values(array_map(fn (string $t): array => ['upload_token' => $t], (array) ($post['upload_tokens'] ?? []))),
            'label_ids' => [$label->id],
            'created_via' => CreatedVia::Api,
            'destinations' => [[
                'social_account_id' => $channel->id,
                'content_type' => ContentType::defaultFor($channel->platform)->value,
                'meta' => $meta,
            ]],
        ]));

        /** @var Post $first */
        $first = $created->first();

        return (string) $first->post_group_id;
    }

    private static function hasLivePost(ReviewItem $item): bool
    {
        return $item->posts()->contains(fn (Post $p): bool => in_array($p->status, [PostStatus::Publishing, PostStatus::Published], true));
    }

    /** @return array<string, mixed> */
    private static function result(ReviewItem $item, string $outcome, ?string $note = null): array
    {
        return [
            'engine_post_id' => $item->engine_post_id,
            'review_item_id' => $item->id,
            'workspace_id' => $item->workspace_id,
            'review_status' => $item->review_status->value,
            'revision' => $item->revision,
            'outcome' => $outcome,
            'note' => $note,
            'review_url' => url('/tantra/review/'.$item->batch_id.'#'.$item->engine_post_id),
        ];
    }
}
