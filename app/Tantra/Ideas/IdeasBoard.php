<?php

declare(strict_types=1);

namespace App\Tantra\Ideas;

use App\Models\Idea;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\LinkedIn\Identity;
use App\Tantra\Review\Models\WorkspaceSettings;

/**
 * tantra: what the upstream Ideas board shows for Tantra ideas (PRD 25.8): a
 * phone preview and badges on each card, a connect prompt for a network with
 * no channel, and the "N posts ready for <Network>" strip.
 */
final class IdeasBoard
{
    /**
     * The board's Tantra props, or null when the workspace has no Tantra idea
     * (the board then stays exactly as upstream draws it).
     *
     * @return array<string, mixed>|null
     */
    public static function props(Workspace $workspace, User $user): ?array
    {
        $ready = IdeaMeta::query()
            ->whereIn('idea_id', Idea::query()->where('workspace_id', $workspace->id)->select('id'))
            ->where('state', IdeaMeta::STATE_IDEA)
            ->selectRaw('platform, count(*) as aggregate')
            ->groupBy('platform')
            ->pluck('aggregate', 'platform');

        $hasAny = $ready->isNotEmpty() || IdeaMeta::query()->whereIn('idea_id', Idea::query()->where('workspace_id', $workspace->id)->select('id'))->exists();
        if (! $hasAny) {
            return null;
        }

        $connected = Networks::connected($workspace->id);
        $hasProfile = filled(WorkspaceSettings::query()->find($workspace->id)?->brand_profile_key);
        Identity::flush();

        $networks = [];
        foreach (Networks::keys() as $key) {
            $identity = Identity::forWorkspace($workspace->id, in_array($key, ['x', 'linkedin_page'], true) ? $key : 'linkedin');
            $networks[$key] = [
                'key' => $key,
                'label' => Networks::label($key),
                'connected' => isset($connected[$key]),
                'by_hand' => Networks::byHand($key),
                'profile' => [
                    'name' => (string) (($identity['name'] ?? '') ?: $workspace->name),
                    'headline' => (string) ($identity['headline'] ?? ''),
                    'avatar' => $identity['avatar'] ?? null,
                    'kind' => $key === 'linkedin_page' ? 'company' : ($identity['kind'] ?? 'person'),
                    'followers_line' => $identity['followers_line'] ?? null,
                    'x_handle' => $identity['x_handle'] ?? null,
                ],
            ];
        }

        return [
            'networks' => $networks,
            'ready' => $ready
                ->map(fn ($count, string $platform): array => [
                    'network' => $platform,
                    'label' => Networks::label($platform),
                    'count' => (int) $count,
                    'connected' => isset($connected[$platform]) || Networks::byHand($platform),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'viewer' => ['name' => $user->name, 'avatar' => $user->photo_url ?? null],
            'can_send' => $hasProfile,
        ];
    }

    /**
     * The card's Tantra part, when the idea is a Tantra idea and its meta was loaded.
     *
     * @return array<string, mixed>|null
     */
    public static function card(Idea $idea): ?array
    {
        if (! $idea->relationLoaded('tantraMeta')) {
            return null;
        }

        /** @var IdeaMeta|null $meta */
        $meta = $idea->getRelation('tantraMeta');
        if ($meta === null) {
            return null;
        }
        $meta->setRelation('idea', $idea);

        return [
            'external_key' => $meta->external_key,
            'platform' => $meta->platform,
            'channel_id' => $meta->channel_id,
            'kind' => $meta->kind,
            'state' => $meta->state,
            'format' => $meta->format,
            'cadence_week' => $meta->cadence_week,
            'target_date' => $meta->target_date?->toDateString(),
            'preview' => TantraIdeas::preview($meta),
            'review_item_id' => $meta->review_item_id,
        ];
    }
}
