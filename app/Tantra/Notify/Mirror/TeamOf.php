<?php

declare(strict_types=1);

namespace App\Tantra\Notify\Mirror;

use App\Tantra\Engagement\EngagementChannels;
use App\Tantra\Provision\TantraWorkspaceLink;
use Illuminate\Support\Facades\Cache;

/**
 * tantra: Speed P3 (B1). The Tantra team id an Engine workspace belongs to, the same mapping the gateway reads Content with: the link the
 * Tantra app wrote (tantra_workspace_links, PV-02) first, else ENGINE_CHANNELS_WORKSPACES. Null when the workspace is mapped to no team, or to
 * more than one (then nobody can say which team a change is for): the caller then sends nothing. Never a workspace name, never a default team.
 */
final class TeamOf
{
    private const TTL = 60;

    public static function workspace(?string $workspaceId): ?string
    {
        if ($workspaceId === null || $workspaceId === '') {
            return null;
        }
        // Kept a minute: a post saved many times in a row asks once. '' stands for "no team".
        $team = Cache::remember(self::key($workspaceId), self::TTL, fn (): string => self::lookup($workspaceId) ?? '');

        return $team === '' ? null : $team;
    }

    public static function forget(string $workspaceId): void
    {
        Cache::forget(self::key($workspaceId));
    }

    private static function key(string $workspaceId): string
    {
        return "tantra-mirror:team:{$workspaceId}";
    }

    private static function lookup(string $workspaceId): ?string
    {
        $linked = TantraWorkspaceLink::query()->where('workspace_id', $workspaceId)->pluck('tantra_workspace_id')->unique()->values();
        if ($linked->count() === 1) {
            return (string) $linked->first();
        }
        if ($linked->count() > 1) {
            return null;
        }

        $teams = [];
        foreach (EngagementChannels::workspaceMap() as $team => $workspaces) {
            if (in_array($workspaceId, $workspaces, true)) {
                $teams[] = (string) $team;
            }
        }

        return count($teams) === 1 ? $teams[0] : null;
    }
}
