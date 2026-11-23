<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: One Content app PRD 3.5, boost consent at join, over the signed gateway. Joining a team is consent to boost: the CRM's invite
 * accept calls PUT .../boost {consent: true}. Turning it off is stored as boost_opted_out_at, never as a null consent. Consent is per
 * person (MemberProfile is keyed by the engine user), so a member of two teams boosts in both.
 *
 *   PUT  /api/gateway/tantra/team/members/{clerk_user_id}/boost   {consent: bool, paused_until?: ISO time|null}
 *   POST /api/gateway/tantra/team/members/{clerk_user_id}/match   {unipile_account_id, name?}: link their Unipile LinkedIn account
 *   GET  /api/gateway/tantra/team/members/{clerk_user_id}/status  their boost status and the blocker() reason
 *
 * The member is found by Clerk user id within the acting person's own Account only.
 */
class GatewayBoostController extends Controller
{
    public function boost(Request $request, string $member): JsonResponse
    {
        $data = $request->validate([
            'consent' => ['required', 'boolean'],
            'paused_until' => ['nullable', 'date'],
        ]);
        $user = $this->member($request, $member);
        $profile = MemberProfile::query()->firstOrNew(['user_id' => $user->id]);

        if ($data['consent']) {
            $profile->boost_consent_at ??= now();
            $profile->boost_opted_out_at = null;
        } else {
            $profile->boost_consent_at = null;
            $profile->boost_opted_out_at ??= now();
        }
        if ($request->exists('paused_until')) {
            $profile->boost_member_paused_until = $data['paused_until'] ?? null;
        }
        $profile->save();

        return response()->json(['data' => self::present($profile->refresh(), $user)]);
    }

    public function match(Request $request, string $member): JsonResponse
    {
        $data = $request->validate([
            'unipile_account_id' => ['required', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);
        $user = $this->member($request, $member);

        $taken = MemberProfile::query()->where('unipile_account_id', $data['unipile_account_id'])->where('user_id', '!=', $user->id)->exists();
        abort_if($taken, Response::HTTP_CONFLICT, 'That LinkedIn account is already linked to another member.');

        $profile = MemberProfile::query()->firstOrNew(['user_id' => $user->id]);
        $profile->forceFill([
            'unipile_account_id' => $data['unipile_account_id'],
            'unipile_name' => $data['name'] ?? $profile->unipile_name,
            'unipile_match_method' => 'tantra',
            'unipile_confirmed_by' => $request->user()->id,
            'unipile_confirmed_at' => now(),
        ])->save();

        return response()->json(['data' => self::present($profile->refresh(), $user)]);
    }

    public function status(Request $request, string $member): JsonResponse
    {
        $user = $this->member($request, $member);
        $profile = MemberProfile::query()->find($user->id) ?? new MemberProfile(['user_id' => $user->id]);

        return response()->json(['data' => self::present($profile, $user)]);
    }

    /** @return array<string, mixed> */
    public static function present(MemberProfile $profile, User $user): array
    {
        return [
            'member' => $user->clerk_user_id,
            'status' => $profile->status(),
            'blocker' => $profile->blocker(),
            'consent_at' => $profile->boost_consent_at?->toIso8601ZuluString(),
            'opted_out_at' => $profile->boost_opted_out_at?->toIso8601ZuluString(),
            'paused_until' => $profile->boost_member_paused_until?->isFuture() ? $profile->boost_member_paused_until->toIso8601ZuluString() : null,
            'restricted_until' => $profile->boost_paused_until?->isFuture() ? $profile->boost_paused_until->toIso8601ZuluString() : null,
            'linkedin' => $profile->unipile_account_id === null ? null : [
                'unipile_account_id' => $profile->unipile_account_id,
                'name' => $profile->linkedin_name ?: $profile->unipile_name,
                'confirmed_at' => $profile->unipile_confirmed_at?->toIso8601ZuluString(),
            ],
        ];
    }

    private function member(Request $request, string $member): User
    {
        abort_unless(preg_match('/^user_[A-Za-z0-9]{10,64}$/', $member) === 1, Response::HTTP_NOT_FOUND);
        $user = User::query()->where('clerk_user_id', $member)->where('account_id', $request->user()->account_id)->first();
        abort_if($user === null, response()->json([
            'message' => 'This member has not signed in to the content engine yet.', 'code' => 'no_engine_user',
        ], Response::HTTP_NOT_FOUND));

        return $user;
    }
}
