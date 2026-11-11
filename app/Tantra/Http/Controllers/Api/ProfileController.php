<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** tantra: which engine profile maps to which workspace and channels (PRD 10.1 step 3). */
class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;
        $settings = WorkspaceSettings::query()->whereIn('workspace_id', Workspace::query()->where('account_id', $accountId)->select('id'))->get()->keyBy('workspace_id');

        $data = Workspace::query()->where('account_id', $accountId)->orderBy('created_at')->get()->map(fn (Workspace $w): array => [
            'workspace_id' => $w->id,
            'name' => $w->name,
            'profile' => $settings->get($w->id)?->brand_profile_key,
            'review_owner_user_id' => $settings->get($w->id)?->review_owner_user_id,
            'timezone' => $settings->get($w->id)?->timezone,
            'channels' => SocialAccount::query()->where('workspace_id', $w->id)->get()->map(fn (SocialAccount $a): array => [
                'id' => $a->id,
                'platform' => $a->platform->value,
                'name' => $a->display_name,
            ])->values(),
        ])->values();

        return response()->json(['data' => $data]);
    }
}
