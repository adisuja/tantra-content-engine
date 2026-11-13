<?php

declare(strict_types=1);

namespace App\Tantra\Ideas;

use App\Models\User;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * tantra: what the ideas API, the MCP tools and the board share (PRD 25.8):
 * the rules for one idea, which workspaces a user may touch, the list query
 * and the shape an idea is returned in.
 */
final class TantraIdeas
{
    public const MAX_BATCH = 200;

    public const MEDIA_TYPES = ['text', 'image', 'images', 'carousel', 'document', 'video', 'poll', 'pending'];

    /** A preview file is a public https link or a preview stored by POST /api/tantra/previews. */
    public const FILE_PATTERN = '/^(https:\/\/\S+|tantra\/previews\/\S+)$/';

    /**
     * The rules for one idea. $prefix is "ideas.*." for a bulk upsert; $patch
     * makes every field optional and leaves out the key and the workspace.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = '', bool $patch = false): array
    {
        $p = $prefix;
        $rules = [
            "{$p}stage" => ['nullable', 'string', 'max:100'],
            "{$p}title" => ['nullable', 'string', 'max:255'],
            "{$p}body" => ['nullable', 'string', 'max:'.AiPromptRules::PROMPT_MAX_LENGTH],
            "{$p}platform" => [$patch ? 'sometimes' : 'required', 'string', Rule::in(Networks::keys())],
            "{$p}channel_id" => ['nullable', 'uuid'],
            "{$p}format" => ['nullable', 'string', 'max:255'],
            "{$p}template_key" => ['nullable', 'string', 'max:32'],
            "{$p}hook" => ['nullable', 'string', 'max:2000'],
            "{$p}preview" => ['nullable', 'array'],
            "{$p}preview.text" => ['nullable', 'string', 'max:10000'],
            "{$p}preview.title" => ['nullable', 'string', 'max:255'],
            "{$p}preview.first_comment" => ['nullable', 'string', 'max:3000'],
            "{$p}preview.thread" => ['nullable', 'array', 'max:25'],
            "{$p}preview.thread.*" => ['string', 'max:3000'],
            "{$p}preview.media" => ['nullable', 'array'],
            "{$p}preview.media.type" => ['nullable', 'string', Rule::in(self::MEDIA_TYPES)],
            "{$p}preview.media.title" => ['nullable', 'string', 'max:255'],
            "{$p}preview.media.files" => ['nullable', 'array', 'max:20'],
            "{$p}preview.media.files.*" => ['string', 'max:1024', 'regex:'.self::FILE_PATTERN],
            "{$p}preview.media.video" => ['nullable', 'string', 'max:1024', 'regex:'.self::FILE_PATTERN],
            "{$p}preview.media.poster" => ['nullable', 'string', 'max:1024', 'regex:'.self::FILE_PATTERN],
            "{$p}preview.media.tall" => ['nullable', 'boolean'],
            "{$p}upload_tokens" => ['nullable', 'array', 'max:20'],
            "{$p}upload_tokens.*" => ['uuid'],
            "{$p}kind" => ['nullable', 'string', Rule::in(IdeaMeta::KINDS)],
            "{$p}source_review_item_id" => ['nullable', 'uuid'],
            "{$p}source_post_id" => ['nullable', 'uuid'],
            "{$p}cadence_week" => ['nullable', 'integer', 'min:0', 'max:52'],
            "{$p}target_date" => ['nullable', 'date_format:Y-m-d'],
            "{$p}agent_job_id" => ['nullable', 'uuid'],
            // in_review is only ever set by Send to review.
            "{$p}state" => ['nullable', 'string', Rule::in([IdeaMeta::STATE_IDEA, IdeaMeta::STATE_DROPPED])],
        ];

        if (! $patch) {
            $rules["{$p}external_key"] = ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'];
            $rules["{$p}workspace_id"] = ['nullable', 'uuid'];
            $rules["{$p}profile"] = ['nullable', 'string', 'max:64'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public static function filterRules(): array
    {
        return [
            'workspace_id' => ['nullable', 'uuid'],
            'profile' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'string', Rule::in(IdeaMeta::KINDS)],
            'state' => ['nullable', 'string', Rule::in(IdeaMeta::STATES)],
            'platform' => ['nullable', 'string', Rule::in(Networks::keys())],
            'batch' => ['nullable', 'string', 'max:32'],
            'stage' => ['nullable', 'string', 'max:100'],
            'external_key' => ['nullable', 'string', 'max:128'],
            'since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The workspaces a user may work on ideas in: every workspace of the
     * Account for its owner, else only the current one.
     *
     * @return Collection<int, string>
     */
    public static function workspaceIdsFor(User $user): Collection
    {
        if ($user->account?->owner_id === $user->id) {
            return Workspace::query()->where('account_id', $user->account_id)->pluck('id');
        }

        return collect(array_filter([$user->current_workspace_id]));
    }

    /** The workspace named by id, else by profile key, else the default. Null when none or not allowed. */
    public static function resolveWorkspace(User $user, ?string $workspaceId, ?string $profile, ?Workspace $default = null): ?Workspace
    {
        $allowed = self::workspaceIdsFor($user);

        if (filled($workspaceId)) {
            return $allowed->contains($workspaceId) ? Workspace::query()->find($workspaceId) : null;
        }

        if (filled($profile)) {
            $id = WorkspaceSettings::query()->where('brand_profile_key', $profile)->whereIn('workspace_id', $allowed)->value('workspace_id');

            return $id ? Workspace::query()->find($id) : null;
        }

        return $default !== null && $allowed->contains($default->id) ? $default : null;
    }

    /** The stage name a batch number lands in. */
    public static function batchStage(string $batch): string
    {
        return 'Batch '.$batch;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<IdeaMeta>
     */
    public static function query(User $user, array $filters): Builder
    {
        $allowed = self::workspaceIdsFor($user);
        $stage = filled($filters['batch'] ?? null) ? self::batchStage((string) $filters['batch']) : ($filters['stage'] ?? null);
        $since = filled($filters['since'] ?? null) ? CarbonImmutable::parse((string) $filters['since'])->utc() : null;

        return IdeaMeta::query()
            ->where('account_id', $user->account_id)
            ->whereHas('idea', function (Builder $q) use ($allowed, $filters, $stage): void {
                $q->whereIn('workspace_id', $allowed)
                    ->when($filters['workspace_id'] ?? null, fn (Builder $q, string $id) => $q->where('workspace_id', $id))
                    ->when($stage, fn (Builder $q, string $name) => $q->whereHas('stage', fn (Builder $s) => $s->where('name', $name)));
            })
            ->when($filters['profile'] ?? null, fn (Builder $q, string $v) => $q->where('profile_key', $v))
            ->when($filters['kind'] ?? null, fn (Builder $q, string $v) => $q->where('kind', $v))
            ->when($filters['state'] ?? null, fn (Builder $q, string $v) => $q->where('state', $v))
            ->when($filters['platform'] ?? null, fn (Builder $q, string $v) => $q->where('platform', $v))
            ->when($filters['external_key'] ?? null, fn (Builder $q, string $v) => $q->where('external_key', $v))
            ->when($since, fn (Builder $q, CarbonImmutable $at) => $q->where(fn (Builder $w) => $w
                ->where('updated_at', '>=', $at)
                ->orWhereHas('idea', fn (Builder $i) => $i->where('updated_at', '>=', $at))))
            ->with(['idea.stage', 'reviewItem'])
            ->orderBy('updated_at')
            ->orderBy('idea_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, IdeaMeta>
     */
    public static function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return self::query($user, $filters)->paginate((int) ($filters['per_page'] ?? 100), page: (int) ($filters['page'] ?? 1));
    }

    /** @return array<string, mixed> */
    public static function present(IdeaMeta $meta): array
    {
        $idea = $meta->idea;
        $item = $meta->review_item_id ? $meta->reviewItem : null;

        return [
            'id' => $meta->idea_id,
            'external_key' => $meta->external_key,
            'workspace_id' => $idea?->workspace_id,
            'profile' => $meta->profile_key,
            'stage' => $idea?->stage ? ['id' => $idea->stage->id, 'name' => $idea->stage->name] : null,
            'title' => $idea?->title,
            'body' => $idea?->body,
            'platform' => $meta->platform,
            'channel_id' => $meta->channel_id,
            'format' => $meta->format,
            'template_key' => $meta->template_key,
            'hook' => $meta->hook,
            'preview' => self::preview($meta),
            'upload_tokens' => array_values($meta->upload_tokens ?? []),
            'kind' => $meta->kind,
            'source_review_item_id' => $meta->source_review_item_id,
            'source_post_id' => $meta->source_post_id,
            'cadence_week' => $meta->cadence_week,
            'target_date' => $meta->target_date?->toDateString(),
            'agent_job_id' => $meta->agent_job_id,
            'state' => $meta->state,
            'review_item_id' => $item?->id,
            'review_url' => $item ? url('/tantra/review/'.$item->batch_id.'#'.$item->engine_post_id) : null,
            'created_at' => $meta->created_at?->toIso8601String(),
            'updated_at' => $meta->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The preview with every field present, so the phone renderer never meets a gap.
     *
     * @return array{text: string, title: ?string, first_comment: ?string, thread: list<string>, media: array{type: ?string, title: ?string, files: list<string>, video: ?string, poster: ?string, tall: bool}}
     */
    public static function preview(IdeaMeta $meta): array
    {
        $p = (array) ($meta->preview ?? []);
        $files = array_values(array_filter((array) data_get($p, 'media.files', []), 'is_string'));

        return [
            'text' => (string) ($p['text'] ?? $meta->idea?->body ?? ''),
            'title' => isset($p['title']) ? (string) $p['title'] : null,
            'first_comment' => isset($p['first_comment']) ? (string) $p['first_comment'] : null,
            'thread' => array_values(array_filter((array) ($p['thread'] ?? []), 'is_string')),
            'media' => [
                'type' => data_get($p, 'media.type'),
                'title' => data_get($p, 'media.title'),
                'files' => array_map(self::fileUrl(...), $files),
                'video' => ($v = data_get($p, 'media.video')) ? self::fileUrl((string) $v) : null,
                'poster' => ($v = data_get($p, 'media.poster')) ? self::fileUrl((string) $v) : null,
                'tall' => (bool) data_get($p, 'media.tall', false),
            ],
        ];
    }

    /** A stored preview path becomes its public link; a link stays as it is. */
    public static function fileUrl(string $file): string
    {
        return str_starts_with($file, 'tantra/previews/') ? Storage::url($file) : $file;
    }
}
