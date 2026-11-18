<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\AgentJobs;
use App\Tantra\Ideas\TantraIdeas;
use App\Tantra\LinkedIn\Identity;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * tantra: Repost and the weekly repurpose (PRD 25.10). Builds the job input in
 * the Engine (the source post, each target with its network's preset and the
 * Account's own rules) and queues one agent job; RepostApplier turns the
 * result into ideas and, for connected channels, review items.
 */
final class Reposts
{
    /** Our channel platforms by the network names presets and ideas use. */
    public const NETWORK_OF = [
        'linkedin' => 'linkedin', 'unipile-linkedin' => 'linkedin', 'zernio-linkedin-page' => 'linkedin', 'linkedin-page' => 'linkedin',
        'x' => 'x', 'facebook' => 'facebook', 'instagram' => 'instagram', 'instagram-facebook' => 'instagram',
        'youtube' => 'youtube', 'threads' => 'threads', 'tiktok' => 'tiktok', 'bluesky' => 'bluesky', 'pinterest' => 'pinterest',
    ];

    /**
     * Where this person can repost: every channel in the workspaces they can use, grouped by workspace, and the
     * networks nobody has connected yet.
     *
     * @return array{channels: list<array<string, mixed>>, unconnected: list<string>}
     */
    public static function targets(User $user): array
    {
        $workspaceIds = TantraIdeas::workspaceIdsFor($user);
        $channels = SocialAccount::query()->whereIn('workspace_id', $workspaceIds)->with('workspace')->orderBy('workspace_id')->get()
            ->filter(fn (SocialAccount $c): bool => isset(self::NETWORK_OF[$c->platform->value]))
            ->map(fn (SocialAccount $c): array => [
                'id' => $c->id, 'name' => $c->display_name, 'network' => self::NETWORK_OF[$c->platform->value],
                'platform' => $c->platform->value, 'workspace' => $c->workspace?->name, 'workspace_id' => $c->workspace_id,
                'avatar' => $c->avatar_url, 'connected' => ($c->status->value ?? (string) $c->status) === 'connected',
            ])->values();
        $have = $channels->pluck('network')->unique();

        return [
            'channels' => $channels->all(),
            'unconnected' => collect(Cadence::NETWORKS)->reject(fn (string $n): bool => $have->contains($n))->values()->all(),
        ];
    }

    /**
     * @param  list<array{channel_id?: string, network?: string, rework?: bool}>  $targets
     * @param  array{origin?: string|null, draft_only?: bool}  $extra  kept on the job input (the Tantra app's Repurpose automation)
     */
    public static function start(User $user, ReviewItem $source, array $targets, ?string $note = null, string $kind = 'repost', ?int $cadenceWeek = null, bool $fire = true, array $extra = []): AgentJob
    {
        abort_unless(TantraIdeas::workspaceIdsFor($user)->contains($source->workspace_id) || $user->account?->owner_id === $user->id, 404);
        $resolved = self::resolveTargets($user, self::NETWORK_OF[$source->platform] ?? $source->platform, $targets);
        if ($resolved->isEmpty()) {
            throw ValidationException::withMessages(['targets' => 'Pick at least one channel or network.']);
        }

        $networks = $resolved->pluck('network')->unique()->values();
        $job = AgentJobs::enqueue($kind, [
            'source' => self::source($source),
            'targets' => $resolved->all(),
            'presets' => RepurposePreset::query()->whereIn('network', $networks)->pluck('rules', 'network'),
            'custom_rules' => RepurposeRule::query()->where('account_id', $source->account_id)->whereIn('network', $networks)->pluck('rules', 'network'),
            'note' => $note,
            'cadence_week' => $cadenceWeek,
            ...self::extra($extra),
        ], $user, $source->workspace_id, $source, $fire);

        // Note every network this post now goes to, so the cadence never sends it there again (review M6).
        foreach ($resolved->reject(fn (array $t): bool => (bool) ($t['rework'] ?? false))->pluck('network')->unique() as $network) {
            RepurposeProgress::query()->firstOrCreate(
                ['source_review_item_id' => $source->id, 'network' => $network],
                ['account_id' => $source->account_id, 'status' => 'queued', 'via' => $kind === 'repurpose' ? 'cadence' : 'repost', 'agent_job_id' => $job->id],
            );
        }

        return $job;
    }

    /**
     * A repost of a post that is not a review item (the Tantra app's Repurpose automation: a post read from a feed, or from a
     * connected profile). Same agent job and targets as start(); the job's source is the text given. Nothing is published:
     * RepostApplier puts each version in Review (a connected channel) or in Ideas.
     *
     * @param  array{key: string, text: string, url?: string|null, published_at?: string|null, network?: string|null, channel_id?: string|null, kind: string}  $post
     * @param  list<array{channel_id?: string, network?: string}>  $targets
     * @param  array{origin?: string|null, draft_only?: bool}  $extra
     */
    public static function startFromPost(User $user, string $workspaceId, array $post, array $targets, ?string $note = null, array $extra = []): AgentJob
    {
        abort_unless(TantraIdeas::workspaceIdsFor($user)->contains($workspaceId) || $user->account?->owner_id === $user->id, 404);
        $network = filled($post['network'] ?? null) ? (self::NETWORK_OF[(string) $post['network']] ?? (string) $post['network']) : null;
        $resolved = self::resolveTargets($user, $network, $targets);
        if ($resolved->isEmpty()) {
            throw ValidationException::withMessages(['targets' => 'Pick at least one channel or network.']);
        }
        $networks = $resolved->pluck('network')->unique()->values();
        $account = Workspace::query()->whereKey($workspaceId)->value('account_id') ?? $user->account_id;

        return AgentJobs::enqueue('repost', [
            'source' => [
                'review_item_id' => null, 'engine_post_id' => null, 'external_key' => mb_substr((string) $post['key'], 0, 300),
                'kind' => (string) $post['kind'], 'platform' => $network, 'profile' => null, 'identity' => Identity::forWorkspace($workspaceId),
                'text' => (string) $post['text'], 'first_comment' => null, 'thread' => [], 'format' => null, 'sample' => null,
                'sources' => [], 'numbers_for_signoff' => [], 'media' => ['type' => null, 'files' => []],
                'published_at' => $post['published_at'] ?? null, 'url' => $post['url'] ?? null,
            ],
            'targets' => $resolved->all(),
            'presets' => RepurposePreset::query()->whereIn('network', $networks)->pluck('rules', 'network'),
            'custom_rules' => RepurposeRule::query()->where('account_id', $account)->whereIn('network', $networks)->pluck('rules', 'network'),
            'note' => $note,
            'cadence_week' => null,
            ...self::extra($extra),
        ], $user, $workspaceId);
    }

    /**
     * @param  array{origin?: string|null, draft_only?: bool}  $extra
     * @return array<string, mixed>
     */
    private static function extra(array $extra): array
    {
        return array_filter([
            'origin' => filled($extra['origin'] ?? null) ? (string) $extra['origin'] : null,
            'draft_only' => ($extra['draft_only'] ?? false) ? true : null,
        ], fn ($v): bool => $v !== null);
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function resolveTargets(User $user, ?string $sourceNetwork, array $targets): Collection
    {
        $allowed = collect(self::targets($user)['channels'])->keyBy('id');

        return collect($targets)->map(function (array $t) use ($allowed, $sourceNetwork): ?array {
            if (filled($t['channel_id'] ?? null)) {
                $c = $allowed->get($t['channel_id']);
                if ($c === null) {
                    return null;
                }

                return ['target' => $c['id'], 'channel_id' => $c['id'], 'network' => $c['network'], 'platform' => $c['platform'], 'name' => $c['name'],
                    'workspace_id' => $c['workspace_id'], 'connected' => $c['connected'], 'rework' => $c['network'] === $sourceNetwork];
            }
            $network = (string) ($t['network'] ?? '');

            return in_array($network, Cadence::NETWORKS, true)
                ? ['target' => $network, 'channel_id' => null, 'network' => $network, 'platform' => $network, 'name' => ucfirst($network), 'workspace_id' => null, 'connected' => false, 'rework' => false]
                : null;
        })->filter()->values();
    }

    /** @return array<string, mixed> the post as the agent needs it, read by the Engine (review M7) */
    public static function source(ReviewItem $item): array
    {
        $p = $item->payload ?? [];
        $manifest = $item->media_manifest ?? [];
        $post = $item->posts()->first();

        return [
            'review_item_id' => $item->id, 'engine_post_id' => $item->engine_post_id, 'platform' => $item->platform,
            'profile' => $p['profile'] ?? null, 'identity' => Identity::forWorkspace((string) $item->workspace_id),
            'text' => (string) ($p['text'] ?? $post?->content ?? ''), 'first_comment' => $p['first_comment'] ?? null,
            'thread' => array_values((array) ($p['thread'] ?? [])), 'format' => $item->format, 'sample' => $p['sample'] ?? null,
            'sources' => $item->sources ?? [], 'numbers_for_signoff' => $item->numbers_for_signoff ?? [],
            'media' => [
                'type' => $manifest['type'] ?? null,
                'files' => collect($manifest['previews'] ?? [])->map(fn (string $path): string => Storage::url($path))->values()->all(),
            ],
            'published_at' => $post?->published_at?->toIso8601String(), 'url' => $post?->platform_url,
        ];
    }

    /** @return list<string> the network names a channel platform belongs to (for "connect X") */
    public static function networkOf(Platform $platform): ?string
    {
        return self::NETWORK_OF[$platform->value] ?? null;
    }
}
