<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Crm\CrmPostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * tantra: the two changes the Tantra app's Content > Drafts makes, server to server (tantra-crm
 * docs/crm/engine-patches/p4-native-content/README.md). Shared key (EnsureCrmKey). The post must belong to a workspace the Tantra
 * team id maps to, and the person is found by email inside that workspace; both run through UpdatePost as that person, so every
 * rule of the Engine (approval, composition, network limits) applies as it does in the Engine's own screens.
 *
 *   POST posts/update    change the text of a draft; nothing else changes
 *   POST posts/approval  send a draft for approval at a time; only when approval is required for that person, otherwise nothing is scheduled
 */
class CrmPostActionsController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
            'post' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:10000'],
        ]);
        [$post, $workspace, $actor, $refusal] = $this->resolve($data);
        if ($refusal !== null) {
            return $refusal;
        }

        return $this->run($workspace, $post, ['content' => $data['content']], $actor);
    }

    public function approval(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
            'post' => ['required', 'uuid'],
            'scheduled_at' => ['required', 'date', 'after:now', 'before:2038-01-19'],
        ]);
        [$post, $workspace, $actor, $refusal] = $this->resolve($data);
        if ($refusal !== null) {
            return $refusal;
        }
        if (! $actor->requiresApprovalIn($workspace)) {
            // Scheduling here would publish a post the person only meant to ask about.
            return $this->refuse(409, 'approval_not_required');
        }
        if (trim((string) $post->content) === '') {
            return $this->refuse(422, 'validation', 'Write the text of the post first.');
        }

        return $this->run($workspace, $post, ['status' => PostStatus::Scheduled->value, 'scheduled_at' => $data['scheduled_at']], $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?Post, 1: ?Workspace, 2: ?User, 3: ?JsonResponse}
     */
    private function resolve(array $data): array
    {
        $ids = CrmContent::workspaceIds($data['workspace']);
        $post = $ids === [] ? null : Post::query()->with(['socialAccount', 'labels', 'workspace'])->whereIn('workspace_id', $ids)->find($data['post']);
        if ($post === null) {
            return [null, null, null, $this->refuse(404, 'not_found')];
        }
        $actor = CrmContent::actor($post->workspace, $data['actor_email']);
        if ($actor === null) {
            return [null, null, null, $this->refuse(403, 'unknown_person')];
        }
        if ($post->status !== PostStatus::Draft) {
            return [null, null, null, $this->refuse(409, 'not_a_draft')];
        }

        return [$post, $post->workspace, $actor, null];
    }

    /** @param  array<string, mixed>  $changes */
    private function run(Workspace $workspace, Post $post, array $changes, User $actor): JsonResponse
    {
        try {
            $result = UpdatePost::execute($workspace, $post, $changes, $actor);
        } catch (ValidationException $e) {
            return $this->refuse(422, 'validation', (string) collect($e->errors())->flatten()->first());
        }
        if (data_get($result, 'action') === PostAction::Finalized) {
            // The post was published or finalized while this ran.
            return $this->refuse(409, 'not_a_draft');
        }
        $updated = $result['post']->fresh(['socialAccount', 'labels']);

        return response()->json(['data' => CrmPostPresenter::present($updated)])->header('Cache-Control', 'no-store, private');
    }

    private function refuse(int $status, string $code, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['error' => $code, 'message' => $message], fn ($v): bool => $v !== null), $status);
    }
}
