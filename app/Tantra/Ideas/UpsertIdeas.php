<?php

declare(strict_types=1);

namespace App\Tantra\Ideas;

use App\Actions\Idea\CreateDefaultIdeaStages;
use App\Actions\Idea\CreateIdea;
use App\Actions\Idea\CreateIdeaStage;
use App\Actions\Idea\UpdateIdea;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Actions\DraftMaker;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * tantra: bulk upsert of Tantra ideas by external key (PRD 25.8). A new key
 * makes an upstream idea in the workspace's first stage (or the stage named in
 * `stage`, made when missing) plus its meta row. A known key updates the idea
 * while it is still an idea; a dropped idea or one in review is left alone and
 * reported as skipped. Same input, same result: nothing is ever duplicated.
 */
final class UpsertIdeas
{
    /** Fields of the meta row an upsert or a patch may set. */
    private const META_FIELDS = ['platform', 'channel_id', 'format', 'template_key', 'hook', 'preview', 'upload_tokens', 'kind', 'source_review_item_id', 'source_post_id', 'cadence_week', 'target_date', 'agent_job_id', 'state'];

    /**
     * @param  list<array<string, mixed>>  $ideas  validated with TantraIdeas::rules('ideas.*.')
     * @return list<array<string, mixed>>
     */
    public static function execute(User $user, array $ideas, ?Workspace $default = null): array
    {
        // Every target is checked before anything is written, so a bad entry changes nothing.
        $targets = [];
        $errors = [];
        foreach (array_values($ideas) as $i => $data) {
            $workspace = TantraIdeas::resolveWorkspace($user, $data['workspace_id'] ?? null, $data['profile'] ?? null, $default);
            if ($workspace === null) {
                $errors["ideas.{$i}.profile"] = filled($data['workspace_id'] ?? null) || filled($data['profile'] ?? null)
                    ? 'No workspace you can use matches '.($data['workspace_id'] ?? $data['profile']).'.'
                    : 'Name the workspace with profile or workspace_id.';

                continue;
            }
            foreach (self::referenceErrors($user, $workspace, $data) as $field => $message) {
                $errors["ideas.{$i}.{$field}"] = $message;
            }
            $targets[$i] = $workspace;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $results = [];
        foreach (array_values($ideas) as $i => $data) {
            try {
                $results[] = self::one($user, $targets[$i], $data);
            } catch (UniqueConstraintViolationException) {
                // Another call made the same key a moment ago: this one updates it.
                $results[] = self::one($user, $targets[$i], $data);
            }
        }

        return $results;
    }

    /**
     * One edit of an existing idea (PATCH). A dropped idea comes back with state "idea".
     *
     * @param  array<string, mixed>  $data  validated with TantraIdeas::rules(patch: true)
     */
    public static function patch(User $user, IdeaMeta $meta, array $data): IdeaMeta
    {
        if ($meta->state === IdeaMeta::STATE_IN_REVIEW) {
            throw ValidationException::withMessages(['state' => 'This idea is in review. Change it on the review screen.']);
        }

        /** @var Idea $idea */
        $idea = $meta->idea;
        $workspace = $idea->workspace;
        if (($errors = self::referenceErrors($user, $workspace, [...$data, 'platform' => $data['platform'] ?? $meta->platform])) !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $meta, $idea, $workspace, $data): IdeaMeta {
            self::write($user, $workspace, $idea, $meta, $data);

            return $meta->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function one(User $user, Workspace $workspace, array $data): array
    {
        return DB::transaction(function () use ($user, $workspace, $data): array {
            $key = (string) $data['external_key'];
            $meta = IdeaMeta::query()->where('account_id', $workspace->account_id)->where('external_key', $key)->lockForUpdate()->first();

            if ($meta !== null) {
                $reason = match (true) {
                    $meta->idea?->workspace_id !== $workspace->id => 'This key belongs to an idea in another workspace.',
                    $meta->state === IdeaMeta::STATE_DROPPED => 'Dropped. Patch it back to idea to change it.',
                    $meta->state === IdeaMeta::STATE_IN_REVIEW => 'In review. Change it on the review screen.',
                    default => null,
                };
                if ($reason !== null) {
                    return ['external_key' => $key, 'outcome' => 'skipped', 'reason' => $reason, 'idea' => TantraIdeas::present($meta)];
                }

                /** @var Idea $idea */
                $idea = $meta->idea;
                self::write($user, $workspace, $idea, $meta, $data);

                return ['external_key' => $key, 'outcome' => 'updated', 'reason' => null, 'idea' => TantraIdeas::present($meta->refresh())];
            }

            $stageId = self::stageId($workspace, $data['stage'] ?? null);
            $idea = CreateIdea::execute($workspace, $user, [
                'idea_stage_id' => $stageId,
                'title' => self::title($data),
                'body' => self::body($data),
            ]);

            $meta = new IdeaMeta([
                'idea_id' => $idea->id,
                'account_id' => $workspace->account_id,
                'external_key' => $key,
                'profile_key' => $data['profile'] ?? WorkspaceSettings::query()->find($workspace->id)?->brand_profile_key,
                'kind' => 'new',
                'state' => IdeaMeta::STATE_IDEA,
            ]);
            $meta->fill(self::metaAttributes($workspace, $data));
            $meta->save();
            self::hold($workspace, $meta->upload_tokens ?? []);

            return ['external_key' => $key, 'outcome' => 'created', 'reason' => null, 'idea' => TantraIdeas::present($meta->refresh())];
        });
    }

    /** @param array<string, mixed> $data */
    private static function write(User $user, Workspace $workspace, Idea $idea, IdeaMeta $meta, array $data): void
    {
        $changes = [];
        if (array_key_exists('title', $data)) {
            $changes['title'] = self::title($data) ?? $idea->title;
        }
        if (array_key_exists('body', $data) || filled(data_get($data, 'preview.text'))) {
            $changes['body'] = self::body($data) ?? $idea->body;
        }
        if (filled($data['stage'] ?? null)) {
            $changes['idea_stage_id'] = self::stageId($workspace, (string) $data['stage']);
        }
        if ($changes !== []) {
            UpdateIdea::execute($idea, $changes);
        }

        $meta->fill(self::metaAttributes($workspace, $data));
        if (array_key_exists('profile', $data) && filled($data['profile'])) {
            $meta->profile_key = (string) $data['profile'];
        }
        $meta->save();
        if (! $meta->wasChanged()) {
            $meta->touch(); // a pull by `since` sees every write, even one that changed only the idea
        }

        self::hold($workspace, $meta->upload_tokens ?? []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function metaAttributes(Workspace $workspace, array $data): array
    {
        $attributes = Arr::only($data, self::META_FIELDS);
        if (array_key_exists('kind', $attributes) && blank($attributes['kind'])) {
            $attributes['kind'] = 'new';
        }
        if (array_key_exists('state', $attributes) && blank($attributes['state'])) {
            unset($attributes['state']);
        }
        if (array_key_exists('upload_tokens', $attributes)) {
            $attributes['upload_tokens'] = array_values(array_unique((array) $attributes['upload_tokens']));
        }

        // Full-quality uploads with no preview files show as the preview.
        $tokens = (array) ($attributes['upload_tokens'] ?? []);
        if ($tokens !== [] && array_key_exists('preview', $attributes) && blank(data_get($attributes, 'preview.media.files'))) {
            $media = Media::query()->where('workspace_id', $workspace->id)->whereIn('upload_token', $tokens)->orderBy('order')->get();
            $images = $media->filter(fn (Media $m): bool => $m->isImage())->map(fn (Media $m): string => $m->url)->values()->all();
            $video = $media->first(fn (Media $m): bool => $m->isVideo());
            $preview = (array) $attributes['preview'];
            $preview['media'] = [
                ...(array) ($preview['media'] ?? []),
                'type' => data_get($preview, 'media.type') ?? ($video ? 'video' : (count($images) > 1 ? 'images' : 'image')),
                'files' => $images,
                'video' => data_get($preview, 'media.video') ?? $video?->url,
            ];
            $attributes['preview'] = $preview;
        }

        return $attributes;
    }

    /**
     * Channel, source records and uploads must all be in the Account (and the
     * channel and uploads in the idea's workspace).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function referenceErrors(User $user, Workspace $workspace, array $data): array
    {
        $errors = [];
        if (filled($data['channel_id'] ?? null)) {
            $channel = SocialAccount::query()->where('workspace_id', $workspace->id)->find($data['channel_id']);
            if ($channel === null) {
                $errors['channel_id'] = 'That channel is not in this workspace.';
            } elseif (filled($data['platform'] ?? null) && Networks::networkOf($channel->platform) !== $data['platform']) {
                $errors['channel_id'] = 'That channel is not a '.Networks::label((string) $data['platform']).' channel.';
            }
        }
        if (filled($data['source_review_item_id'] ?? null)
            && ! ReviewItem::query()->where('account_id', $user->account_id)->whereKey($data['source_review_item_id'])->exists()) {
            $errors['source_review_item_id'] = 'No review item with that id in this Account.';
        }
        if (filled($data['source_post_id'] ?? null)
            && ! Post::query()->whereKey($data['source_post_id'])->whereIn('workspace_id', Workspace::query()->where('account_id', $user->account_id)->select('id'))->exists()) {
            $errors['source_post_id'] = 'No post with that id in this Account.';
        }
        $tokens = array_values(array_unique((array) ($data['upload_tokens'] ?? [])));
        if ($tokens !== [] && Media::query()->where('workspace_id', $workspace->id)->whereIn('upload_token', $tokens)->count() !== count($tokens)) {
            $errors['upload_tokens'] = 'Upload these files to this workspace first (POST /api/tantra/uploads).';
        }

        return $errors;
    }

    /**
     * Uploads waiting for the review step are kept from the 24-hour pruning, as DraftMaker does.
     *
     * @param  array<int, string>  $tokens
     */
    private static function hold(Workspace $workspace, array $tokens): void
    {
        if ($tokens !== []) {
            Media::query()->where('workspace_id', $workspace->id)->whereIn('upload_token', $tokens)
                ->where('collection', Media::COLLECTION_UPLOADS)
                ->update(['collection' => DraftMaker::HELD_COLLECTION]);
        }
    }

    /** The stage by name (made when missing), else the workspace's first stage. */
    public static function stageId(Workspace $workspace, ?string $name): ?string
    {
        if (filled($name)) {
            $stage = IdeaStage::query()->where('workspace_id', $workspace->id)->where('name', $name)->first();

            return ($stage ?? CreateIdeaStage::execute($workspace, ['name' => $name]))->id;
        }

        if (! $workspace->ideaStages()->exists()) {
            CreateDefaultIdeaStages::execute($workspace);
        }

        return $workspace->ideaStages()->value('id');
    }

    /** @param array<string, mixed> $data */
    private static function title(array $data): ?string
    {
        $title = ($data['title'] ?? null) ?: ($data['hook'] ?? null);

        return filled($title) ? Str::limit(trim((string) strtok((string) $title, "\n")), 250) : null;
    }

    /** @param array<string, mixed> $data */
    private static function body(array $data): ?string
    {
        $body = ($data['body'] ?? null) ?: data_get($data, 'preview.text');

        return filled($body) ? (string) $body : null;
    }
}
