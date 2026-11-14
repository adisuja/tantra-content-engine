<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Models\User;
use App\Models\Workspace;

/**
 * The workspaces one Insights request reads (PRD 26). The Insights tab sets it to
 * every workspace the viewer can see; the upstream readers ask it for the ids to
 * scope by and get their own workspace alone while nothing is set (the channel
 * page, the API, MCP and every job). A scoped instance, so it never outlives the
 * request or the queued job.
 */
final class InsightsScope
{
    /** @var list<string>|null */
    private ?array $workspaceIds = null;

    /** @param  list<string>  $workspaceIds */
    public static function use(array $workspaceIds): void
    {
        app(self::class)->workspaceIds = array_values(array_unique($workspaceIds));
    }

    public static function clear(): void
    {
        app(self::class)->workspaceIds = null;
    }

    /**
     * The workspaces a read for this workspace covers: the Account-wide list while
     * one is set and holds this workspace, else this workspace alone.
     *
     * @return list<string>
     */
    public static function ids(string $workspaceId): array
    {
        $ids = app(self::class)->workspaceIds;

        return $ids !== null && in_array($workspaceId, $ids, true) ? $ids : [$workspaceId];
    }

    /**
     * Every workspace the user can see: the Account owner sees all of the Account's,
     * anyone else the workspaces they are a member of. The current one always counts.
     *
     * @return list<string>
     */
    public static function visibleTo(User $user): array
    {
        $ids = $user->account !== null && $user->account->owner_id === $user->id
            ? Workspace::query()->where('account_id', $user->account_id)->pluck('id')
            : $user->accountWorkspaces()->pluck('workspaces.id');

        return $ids->push($user->current_workspace_id)->filter()->unique()->values()->all();
    }
}
