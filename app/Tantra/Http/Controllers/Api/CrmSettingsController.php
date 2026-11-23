<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Crm\CrmContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: the Tantra app's Content > Settings, server to server and read only (tantra-crm docs/crm/engine-patches/p4-c07b/README.md).
 * The mapped workspaces by name, their channels count, their people by display name and role (never an email, user id or token),
 * and whether the person's posts need approval, by the same rule CreatePosts applies. An unmapped team gets nothing.
 *
 *   POST settings
 */
class CrmSettingsController extends Controller
{
    private const RANK = ['owner' => 4, 'admin' => 3, 'editor' => 2, 'member' => 1];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        if ($ids === []) {
            return $this->ok(['workspaces' => [], 'you' => null]);
        }

        $workspaces = Workspace::query()->whereIn('id', $ids)->withCount('socialAccounts')->with(['owner', 'members'])->orderBy('name')->get();
        $found = $workspaces->map(fn (Workspace $w): array => [$w, CrmContent::actor($w, $data['actor_email'])]);
        if ($found->every(fn (array $pair): bool => $pair[1] === null) && CrmContent::actorInTeam($ids, $data['actor_email']) !== null) {
            // M1: a teammate the Tantra app names joined the team's first workspace as a member; read it again.
            $workspaces = Workspace::query()->whereIn('id', $ids)->withCount('socialAccounts')->with(['owner', 'members'])->orderBy('name')->get();
            $found = $workspaces->map(fn (Workspace $w): array => [$w, CrmContent::actor($w, $data['actor_email'])]);
        }
        if ($found->every(fn (array $pair): bool => $pair[1] === null)) {
            return response()->json(['error' => 'unknown_person'], 403)->header('Cache-Control', 'no-store, private');
        }

        $roles = [];
        $needs = false;
        $rows = $found->map(function (array $pair) use (&$roles, &$needs): array {
            [$workspace, $actor] = $pair;
            $required = $actor !== null && $actor->requiresApprovalIn($workspace);
            if ($actor !== null) {
                $roles[] = $this->role($workspace, $actor);
                $needs = $needs || $required;
            }

            return [
                'id' => $workspace->id,
                'name' => (string) $workspace->name,
                'timezone' => $workspace->owner?->timezone ?: null,
                'channels_count' => (int) $workspace->social_accounts_count,
                'approval_required' => $required,
                'members' => $this->members($workspace),
            ];
        })->values()->all();

        usort($roles, fn (string $a, string $b): int => self::RANK[$b] <=> self::RANK[$a]);

        return $this->ok(['workspaces' => $rows, 'you' => ['role' => $roles[0], 'needs_approval' => $needs]]);
    }

    /** @return list<array{name: string, role: string}> */
    private function members(Workspace $workspace): array
    {
        $people = collect();
        if ($workspace->owner !== null) {
            $people->push(['name' => (string) $workspace->owner->name, 'role' => 'owner']);
        }
        foreach ($workspace->members as $member) {
            if ($member->id !== $workspace->user_id) {
                $people->push(['name' => (string) $member->name, 'role' => $this->role($workspace, $member)]);
            }
        }

        return $people->values()->all();
    }

    /** owner, admin, editor (publishes directly) or member (posts need approval). */
    private function role(Workspace $workspace, User $user): string
    {
        $access = $user->accessIn($workspace);

        return match (true) {
            $workspace->user_id === $user->id || $access['is_owner'] => 'owner',
            $access['is_admin'] => 'admin',
            $access['requires_approval'] => 'member',
            default => 'editor',
        };
    }

    /** @param array<string, mixed> $data */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
