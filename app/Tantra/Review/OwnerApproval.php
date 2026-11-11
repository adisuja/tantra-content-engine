<?php

declare(strict_types=1);

namespace App\Tantra\Review;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;

/**
 * tantra: premise 5 (PRD 10.8). Nothing reaches a teammate's LinkedIn without
 * their approval. Once a workspace's named review owner has joined, anyone else
 * who schedules or publishes there, the Account owner included, sends the post
 * for approval instead, and only the review owner approves.
 *
 * TryPost asks this from its workspace access checks, so the answer is kept
 * for a few seconds per workspace and dropped when settings or logins change.
 */
final class OwnerApproval
{
    private const KEEP_NANOSECONDS = 30_000_000_000;

    /** @var array<string, array{0: ?string, 1: int}> */
    private static array $memo = [];

    public static function someoneElseApproves(Workspace $workspace, User $user): bool
    {
        $owner = self::joinedOwnerId($workspace);

        return $owner !== null && $owner !== $user->id;
    }

    /** The named review owner once they have joined, else null (TryPost's own rules apply). */
    public static function joinedOwnerId(Workspace $workspace): ?string
    {
        $key = (string) $workspace->getKey();
        $hit = self::$memo[$key] ?? null;
        if ($hit !== null && hrtime(true) - $hit[1] < self::KEEP_NANOSECONDS) {
            return $hit[0];
        }

        $named = WorkspaceSettings::query()->whereKey($key)->value('review_owner_user_id');
        $user = $named ? User::query()->find($named) : null;
        $id = $user !== null && ReviewGate::hasJoined($user) ? (string) $named : null;

        self::$memo[$key] = [$id, hrtime(true)];

        return $id;
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
