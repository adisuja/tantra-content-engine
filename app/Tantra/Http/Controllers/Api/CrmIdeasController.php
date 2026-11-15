<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Ideas\TantraIdeas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: POST /api/tantra/engagement/ideas, server to server from the Tantra app's Content > Ideas screen (tantra-crm
 * docs/crm/engine-patches/p4-native-content/README.md). Shared key (EnsureCrmKey); the Tantra team id is the only identity and
 * maps to Engine workspaces; an unknown team gets an empty list. Read only, the same shape as GET /api/tantra/ideas.
 */
class CrmIdeasController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);

        $query = IdeaMeta::query()->with(['idea.stage', 'reviewItem'])->orderByDesc('updated_at')->orderBy('idea_id');
        if ($ids === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('idea', fn (Builder $q) => $q->whereIn('workspace_id', $ids));
        }
        $page = $query->paginate(min(100, (int) ($data['per_page'] ?? 100)), ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (IdeaMeta $meta): array => TantraIdeas::present($meta))->values(),
            'meta' => CrmContent::meta($page),
        ])->header('Cache-Control', 'no-store, private');
    }
}
