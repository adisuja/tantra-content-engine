<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Enums\Post\Status as PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Crm\CrmPostPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * tantra: POST /api/tantra/engagement/posts, server to server from the Tantra app's Content > Drafts and Content > Calendar
 * (tantra-crm docs/crm/engine-patches/p4-native-content/README.md). Shared key (EnsureCrmKey); the Tantra team id is the only
 * identity and maps to Engine workspaces; an unknown team gets an empty list. Read only.
 *
 * Drafts ask for the statuses draft and pending_approval. The Calendar asks for scheduled, publishing, published and failed
 * inside a window: `from` and `to` (both inclusive) cut by published_at, else scheduled_at.
 */
class CrmPostsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'statuses' => ['required', 'array', 'min:1', 'max:6'],
            'statuses.*' => ['string', Rule::in(array_column(PostStatus::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $ids = CrmContent::workspaceIds($data['workspace']);

        $query = Post::query()->with(['socialAccount', 'labels'])->orderByDesc('updated_at')->orderBy('id');
        if ($ids === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('workspace_id', $ids)->whereIn('status', array_values(array_unique($data['statuses'])));
            if (filled($data['from'] ?? null)) {
                $query->whereRaw('coalesce(published_at, scheduled_at) >= ?', [CarbonImmutable::parse($data['from'])->utc()->format('Y-m-d H:i:s')]);
            }
            if (filled($data['to'] ?? null)) {
                $query->whereRaw('coalesce(published_at, scheduled_at) <= ?', [CarbonImmutable::parse($data['to'])->utc()->format('Y-m-d H:i:s')]);
            }
        }
        $page = $query->paginate(min(100, (int) ($data['per_page'] ?? 100)), ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (Post $post): array => CrmPostPresenter::present($post))->values(),
            'meta' => CrmContent::meta($page),
        ])->header('Cache-Control', 'no-store, private');
    }
}
