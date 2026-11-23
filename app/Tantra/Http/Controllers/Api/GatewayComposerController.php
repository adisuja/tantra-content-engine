<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Post\DuplicatePost;
use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\UnsplashService;
use App\Tantra\Crm\CrmPostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: One Content app PRD 4.1, the CRM composer's helpers over the signed gateway.
 *
 *   POST /api/gateway/tantra/composer/assist        {action: rewrite|shorten|hooks|fix_tone, text, platform?, tone?}. The engine has
 *                                                    no composer AI of its own: the CRM runs its AI task content.assist. This answers
 *                                                    what that task needs from the engine (the network's length limit and target), so
 *                                                    the copy fits the channel.
 *   GET  /api/gateway/tantra/unsplash?query=&page=  Unsplash photos for the composer (the engine's own key and rate limit)
 *   POST /api/gateway/tantra/posts/{post}/duplicate a copy of the post as a draft (201)
 */
class GatewayComposerController extends Controller
{
    public const array ACTIONS = ['rewrite', 'shorten', 'hooks', 'fix_tone'];

    public function assist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'text' => ['required', 'string', 'max:10000'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'tone' => ['nullable', 'string', 'max:60'],
        ]);
        $platform = isset($data['platform']) ? Platform::from($data['platform']) : null;

        return response()->json(['data' => [
            'handled_by' => 'crm',
            'ai_task' => 'content.assist',
            'action' => $data['action'],
            'variants' => $data['action'] === 'hooks' ? 3 : 1,
            'tone' => $data['tone'] ?? null,
            'platform' => $platform?->value,
            'max_length' => $platform?->maxContentLength(),
            'target_length' => $platform?->recommendedAiContentLength(),
            'length' => mb_strlen($data['text']),
        ]]);
    }

    public function unsplash(Request $request, UnsplashService $unsplash): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $this->authorize('createPost', $request->user()->currentWorkspace);

        $results = $unsplash->search(query: (string) $data['query'], page: (int) ($data['page'] ?? 1));

        return $results === null
            ? response()->json(['message' => __('posts.composer.unsplash.error')], Response::HTTP_SERVICE_UNAVAILABLE)
            : response()->json(['data' => $results]);
    }

    public function duplicate(Request $request, string $post): JsonResponse
    {
        $row = Str::isUuid($post) ? Post::query()->where('workspace_id', $request->user()->currentWorkspace->id)->find($post) : null;
        abort_if($row === null, Response::HTTP_NOT_FOUND);
        $this->authorize('duplicate', $row);

        $row->load(['socialAccount', 'labels']);
        $copy = DuplicatePost::execute($row, $request->user());

        return response()->json(['message' => 'Duplicated.', 'data' => CrmPostPresenter::present($copy->refresh()->load('labels'))], Response::HTTP_CREATED);
    }
}
