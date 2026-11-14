<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Agent\Admin;
use App\Tantra\Rebrand\Rebrand;
use App\Tantra\Rebrand\Rebrands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: profile rebrands on the Team page (PRD 25.11). The person, the
 * Account owner or an admin can ask for one; only the person applies it.
 */
class RebrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = Rebrand::query()->where('account_id', $user->account_id)
            ->when(! $this->canSeeAll($user), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->latest()->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (Rebrand $r): array => [...$r->toCard(), 'user_id' => $r->user_id])->values()]);
    }

    public function start(Request $request, User $member): JsonResponse
    {
        $user = $request->user();
        abort_unless($member->account_id === $user->account_id && ($member->id === $user->id || $this->canSeeAll($user)), 404);
        $rebrand = Rebrands::start($user, $member);

        return response()->json(['message' => $rebrand->status === 'writing'
            ? 'Writing the rebrand. It shows here when it is ready.'
            : 'A rebrand is already waiting for a look.', 'data' => $rebrand->toCard()], 201);
    }

    public function headline(Request $request, Rebrand $rebrand): JsonResponse
    {
        $headline = (string) $request->validate(['headline' => ['required', 'string', 'max:220']])['headline'];
        $out = Rebrands::applyHeadline($request->user(), $rebrand, $headline);

        return response()->json(['message' => $out['message'], 'applied' => $out['applied'], 'data' => $rebrand->refresh()->toCard()]);
    }

    public function dismiss(Request $request, Rebrand $rebrand): JsonResponse
    {
        abort_unless($rebrand->user_id === $request->user()->id || $this->canSeeAll($request->user()), 403);
        $rebrand->forceFill(['status' => 'dismissed'])->save();

        return response()->json(['message' => 'Put away.']);
    }

    private function canSeeAll(User $user): bool
    {
        return $user->account?->owner_id === $user->id || Admin::is($user);
    }
}
