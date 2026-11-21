<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostTemplate;
use App\Models\RssFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * tantra: the composer's RSS feeds and saved post templates, read only, over the signed gateway, for the pinned workspace.
 *
 *   GET /api/gateway/tantra/feeds           the workspace's feeds                      (content:read)
 *   GET /api/gateway/tantra/post-templates  the team's templates and the member's own (content:read)
 */
class GatewayLibraryController extends Controller
{
    public function feeds(Request $request): JsonResponse
    {
        $feeds = RssFeed::query()->where('workspace_id', $request->user()->currentWorkspace->id)->with('collection:id,name')->orderBy('title')->limit(500)->get();

        return response()->json(['data' => $feeds->map(fn (RssFeed $f): array => [
            'ref' => $f->id,
            'title' => $f->display_title,
            'url' => $f->url,
            'site_url' => $f->site_url,
            'icon_url' => $f->icon_url,
            'format' => $f->format->value,
            'collection' => $f->collection?->name,
            'last_succeeded_at' => $f->last_succeeded_at?->toIso8601String(),
            'healthy' => $f->consecutive_failures === 0,
        ])->values()]);
    }

    public function postTemplates(Request $request): JsonResponse
    {
        $user = $request->user();
        $templates = PostTemplate::query()->where('workspace_id', $user->currentWorkspace->id)->visibleTo($user)->orderBy('title')->limit(500)->get();

        return response()->json(['data' => $templates->map(fn (PostTemplate $t): array => [
            'ref' => $t->id,
            'title' => $t->title,
            'emoji' => $t->emoji,
            'description' => $t->description,
            'body' => $t->body,
            'visibility' => $t->visibility->value,
        ])->values()]);
    }
}
