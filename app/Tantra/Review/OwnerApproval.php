<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use WeakMap;

/**
 * tantra: premise 5 (PRD 10.8). Nothing reaches a teammate's LinkedIn without
 * their approval. Once a workspace's named review owner has joined, anyone else
 * who schedules or publishes there, the Account owner included, sends the post
 * for approval instead, and only the review owner approves.
 *
 * TryPost asks this from its workspace access checks, many times a request, so
 * the answer is kept on each loaded workspace (one query per workspace, however
 * many members are checked) and dropped when settings or logins change.
 */
final class OwnerApproval
{
    /** @var WeakMap<Workspace, array{0: ?string, 1: int}>|null */
    private static ?WeakMap $memo = null;

    private static int $generation = 0;

    public static function someoneElseApproves(Workspace $workspace, User $user): bool
    {
        $owner = self::joinedOwnerId($workspace);

        return $owner !== null && $owner !== $user->id;
    }

    /** The named review owner once they have joined, else null (TryPost's own rules apply). */
    public static function joinedOwnerId(Workspace $workspace): ?string
    {
        self::$memo ??= new WeakMap;
        $hit = self::$memo[$workspace] ?? null;
        if ($hit !== null && $hit[1] === self::$generation) {
            return $hit[0];
        }

        $named = WorkspaceSettings::query()->whereKey($workspace->getKey())->value('review_owner_user_id');
        $user = $named ? User::query()->find($named) : null;
        $id = $user !== null && ReviewGate::hasJoined($user) ? (string) $named : null;

        self::$memo[$workspace] = [$id, self::$generation];

        return $id;
    }

    public static function forget(): void
    {
        self::$generation++;
    }
}
