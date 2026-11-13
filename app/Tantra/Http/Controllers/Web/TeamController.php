<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\LinkedIn\LinkedInSync;
use App\Tantra\LinkedIn\SyncLinkedInIdentity;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\ReviewGate;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * tantra: the Team page (PRD 10.5 step 3 and 10.9): each person's consent and
 * expertise, and for the Account owner, which Unipile LinkedIn account belongs
 * to which teammate.
 */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isOwner = $user->account?->owner_id === $user->id;
        $profiles = MemberProfile::query()->whereIn('user_id', User::query()->where('account_id', $user->account_id)->select('id'))->get()->keyBy('user_id');

        $members = User::query()->where('account_id', $user->account_id)->orderBy('name')->get()
            ->filter(fn (User $u): bool => $isOwner || $u->id === $user->id)
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_me' => $u->id === $user->id,
                'unipile_account_id' => $profiles->get($u->id)?->unipile_account_id,
                'unipile_name' => $profiles->get($u->id)?->unipile_name,
                'confirmed' => $profiles->get($u->id)?->unipile_confirmed_at !== null,
                'consent' => $profiles->get($u->id)?->boost_consent_at !== null,
                'paused_until' => $profiles->get($u->id)?->boost_paused_until?->isFuture() ? $profiles->get($u->id)->boost_paused_until->toIso8601String() : null,
                'expertise' => $profiles->get($u->id)?->expertise,
                'checklist' => $this->checklist($u, $profiles->get($u->id)),
                'linkedin' => $this->linkedin($profiles->get($u->id)),
            ])->values();

        return Inertia::render('tantra/team/Index', [
            'members' => $members,
            'isOwner' => $isOwner,
            'boostEnabled' => Boost::enabled(),
            'unipileAccounts' => $isOwner ? $this->unipileAccounts($members->pluck('name')->all()) : [],
        ]);
    }

    /**
     * What LinkedIn says about them, read through their Unipile account.
     *
     * @return array{name: ?string, headline: ?string, photo: ?string, url: ?string, status: ?string, synced_at: ?string}|null
     */
    private function linkedin(?MemberProfile $profile): ?array
    {
        if ($profile?->unipile_account_id === null) {
            return null;
        }

        return [
            'name' => $profile->linkedin_name,
            'headline' => $profile->linkedin_headline,
            'photo' => $profile->linkedinPictureUrl(),
            'url' => $profile->linkedin_profile_url,
            'status' => $profile->unipile_status,
            'synced_at' => $profile->linkedin_synced_at?->toIso8601String(),
        ];
    }

    /**
     * The onboarding checklist (PRD 10.9): joined, LinkedIn connected in a
     * workspace they review, expertise written, Unipile account matched,
     * team comments switched on, an API key for MCP.
     *
     * @return array<string, bool>
     */
    private function checklist(User $user, ?MemberProfile $profile): array
    {
        $reviewed = WorkspaceSettings::query()->where('review_owner_user_id', $user->id)->pluck('workspace_id');

        return [
            'joined' => ReviewGate::hasJoined($user),
            'linkedin' => SocialAccount::query()->whereIn('workspace_id', $reviewed)
                ->whereIn('platform', [Platform::LinkedIn, Platform::UnipileLinkedIn, Platform::ZernioLinkedInPage])->where('status', Status::Connected)->exists(),
            'expertise' => filled($profile?->expertise),
            'unipile' => $profile?->unipile_confirmed_at !== null,
            'consent' => $profile?->boost_consent_at !== null,
            'api_key' => $user->tokens()->where('revoked', false)->exists(),
        ];
    }

    public function consent(Request $request): JsonResponse
    {
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        MemberProfile::query()->updateOrCreate(['user_id' => $request->user()->id], ['boost_consent_at' => $on ? now() : null]);

        return response()->json(['message' => $on ? 'Your approved team comments will be posted in your name.' : 'Nothing will be posted in your name.']);
    }

    public function expertise(Request $request): JsonResponse
    {
        $text = $request->validate(['expertise' => ['nullable', 'string', 'max:600']])['expertise'] ?? null;
        MemberProfile::query()->updateOrCreate(['user_id' => $request->user()->id], ['expertise' => $text]);

        return response()->json(['message' => 'Saved.']);
    }

    public function match(Request $request): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner->account?->owner_id === $owner->id, 403);
        $data = $request->validate(['user_id' => ['required', 'uuid'], 'unipile_account_id' => ['nullable', 'string', 'max:120']]);
        $member = User::query()->where('account_id', $owner->account_id)->findOrFail($data['user_id']);

        $accountId = $data['unipile_account_id'] ?? null;
        $name = $accountId ? collect($this->unipileAccounts([]))->firstWhere('id', $accountId)['name'] ?? null : null;
        if ($accountId !== null && $name === null) {
            return response()->json(['message' => 'That Unipile account was not found.'], 422);
        }
        if ($accountId !== null && MemberProfile::query()->where('unipile_account_id', $accountId)->where('user_id', '!=', $member->id)->exists()) {
            return response()->json(['message' => 'That account is already matched to someone else.'], 422);
        }

        $before = MemberProfile::query()->find($member->id);
        if ($before !== null && $before->unipile_account_id !== $accountId) {
            LinkedInSync::forget($before);
        }
        MemberProfile::query()->updateOrCreate(['user_id' => $member->id], [
            'unipile_account_id' => $accountId,
            'unipile_name' => $name,
            'unipile_match_method' => $accountId ? 'confirmed' : null,
            'unipile_confirmed_by' => $accountId ? $owner->id : null,
            'unipile_confirmed_at' => $accountId ? now() : null,
        ]);

        if ($accountId !== null) {
            SyncLinkedInIdentity::dispatch($member->id); // their name, headline and photo from LinkedIn
        }

        return response()->json(['message' => $accountId ? "{$member->name} is matched to {$name}." : "{$member->name} has no account now."]);
    }

    /**
     * Tantra's Unipile LinkedIn accounts, cached for ten minutes, with the
     * Engine member whose name matches (a suggestion only).
     *
     * @param  list<string>  $names
     * @return list<array{id: string, name: string, status: string, suggested_for: ?string}>
     */
    private function unipileAccounts(array $names): array
    {
        if (! UnipileClient::configured()) {
            return [];
        }

        try {
            $accounts = Cache::remember('tantra.unipile.accounts', 600, fn (): array => app(UnipileClient::class)->accounts());
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        return collect($accounts)
            ->filter(fn ($a): bool => is_array($a) && strtolower((string) ($a['type'] ?? $a['provider'] ?? 'linkedin')) === 'linkedin')
            ->map(fn (array $a): array => [
                'id' => (string) ($a['id'] ?? ''),
                'name' => (string) ($a['name'] ?? ''),
                'status' => strtolower((string) ($a['status'] ?? data_get($a, 'sources.0.status') ?? '')),
                'suggested_for' => collect($names)->first(fn (string $n): bool => Str::lower($n) === Str::lower((string) ($a['name'] ?? ''))),
            ])->values()->all();
    }
}
