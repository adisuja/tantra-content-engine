<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Post\CreatePosts;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Crm\CrmContent;
use App\Tantra\Crm\CrmDeleteRefused;
use App\Tantra\Crm\CrmNetworkDelete;
use App\Tantra\Crm\CrmPostPresenter;
use App\Tantra\Repurpose\Reposts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * tantra: the Tantra app's Content > Publish, server to server (tantra-crm docs/crm/engine-patches/p4-phase3/README.md). Shared key
 * (EnsureCrmKey); the Tantra team id maps to Engine workspaces. A post is made exactly as the Engine's own API makes it
 * (CreatePosts::execute as the person found by email), so every Engine rule applies: approval (a person whose posts need approval gets
 * pending_approval, never a published post), network limits, scheduling. Text posts for now: no media.
 *
 *   POST publish/channels   the channels of the team, by name, with whether each is connected (never a token)
 *   POST publish            schedule a post, or publish it now, to one or more channels of one workspace
 *   POST publish/delete     delete a published post on its network (D33, docs/crm/engine-patches/d33-delete-post/README.md)
 */
class CrmPublishController extends Controller
{
    public function channels(Request $request): JsonResponse
    {
        $data = $request->validate(['workspace' => ['required', 'string', 'max:100']]);
        $ids = CrmContent::workspaceIds($data['workspace']);
        $rows = $ids === [] ? collect() : SocialAccount::query()->whereIn('workspace_id', $ids)->with('workspace')->orderBy('workspace_id')->orderBy('display_name')->get();

        return response()->json(['data' => $rows->map(fn (SocialAccount $c): array => [
            'id' => $c->id,
            'name' => (string) ($c->display_name ?: $c->username),
            'username' => $c->username,
            'platform' => $c->platform->value,
            'network' => Reposts::NETWORK_OF[$c->platform->value] ?? $c->platform->value,
            'workspace' => $c->workspace?->name,
            'connected' => $c->status === AccountStatus::Connected,
        ])->values()])->header('Cache-Control', 'no-store, private');
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:100'],
            'actor_email' => ['required', 'email', 'max:255'],
            'channels' => ['required', 'array', 'min:1', 'max:10'],
            'channels.*' => ['uuid', 'distinct'],
            'content' => ['required', 'string', 'max:10000'],
            'mode' => ['required', 'in:now,schedule'],
            'scheduled_at' => ['required_if:mode,schedule', 'nullable', 'date', 'after:now', 'before:2038-01-19'],
            'client_key' => ['nullable', 'string', 'max:80'],
        ]);
        if (trim($data['content']) === '') {
            return $this->refuse(422, 'validation', 'Write the text of the post first.');
        }

        $ids = CrmContent::workspaceIds($data['workspace']);
        $accounts = $ids === [] ? collect() : SocialAccount::query()->whereIn('workspace_id', $ids)->whereIn('id', $data['channels'])->with('workspace')->get();
        if ($accounts->count() !== count($data['channels'])) {
            return $this->refuse(404, 'unknown_channel');
        }
        if ($accounts->pluck('workspace_id')->unique()->count() > 1) {
            return $this->refuse(422, 'one_workspace', 'Pick channels from one workspace at a time.');
        }
        $offline = $accounts->reject(fn (SocialAccount $c): bool => $c->status === AccountStatus::Connected);
        if ($offline->isNotEmpty()) {
            return $this->refuse(422, 'channel_not_connected', 'Connect '.$offline->map(fn (SocialAccount $c): string => (string) ($c->display_name ?: $c->username))->implode(', ').' again before posting to it.');
        }
        $workspace = $accounts->first()->workspace;
        $actor = CrmContent::actor($workspace, $data['actor_email']);
        if ($actor === null) {
            return $this->refuse(403, 'unknown_person');
        }

        // A post goes out once: the same key (or the same words to the same channels at the same time) is refused for ten minutes, not published twice.
        $channelIds = collect($data['channels'])->sort()->values()->all();
        $key = 'tantra:crm-publish:'.sha1($workspace->id.'|'.($data['client_key'] ?? implode(',', $channelIds).'|'.$data['mode'].'|'.($data['scheduled_at'] ?? '').'|'.$data['content']));
        if (! Cache::add($key, 1, 600)) {
            return $this->refuse(409, 'duplicate');
        }

        try {
            $posts = CreatePosts::execute($workspace, $actor, [
                'status' => $data['mode'] === 'now' ? PostStatus::Publishing->value : PostStatus::Scheduled->value,
                'content' => $data['content'],
                'media' => [],
                'scheduled_at' => $data['mode'] === 'schedule' ? $data['scheduled_at'] : null,
                'label_ids' => [],
                'created_via' => CreatedVia::Api,
                'destinations' => collect($data['channels'])->map(fn (string $id): array => ['social_account_id' => $id])->all(),
            ]);
        } catch (ValidationException $e) {
            Cache::forget($key);

            return $this->refuse(422, 'validation', (string) collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            Cache::forget($key);
            throw $e;
        }

        $presented = $posts->map(fn (Post $post): array => CrmPostPresenter::present($post->load(['socialAccount', 'labels'])))->values();

        return response()->json([
            'data' => $presented,
            'approval' => $posts->contains(fn (Post $post): bool => $post->status === PostStatus::PendingApproval),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function delete(Request $request, CrmNetworkDelete $network): JsonResponse
    {
        try {
            $data = $request->validate([
                'workspace' => ['required', 'string', 'max:100'],
                'actor_email' => ['required', 'email', 'max:255'],
                'post_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return $this->refuse(422, 'validation', (string) collect($e->errors())->flatten()->first());
        }

        // A post of another team, or of no mapped team, is the same not_found: nothing tells whether it exists.
        $ids = CrmContent::workspaceIds($data['workspace']);
        $post = $ids === [] ? null : Post::query()->whereIn('workspace_id', $ids)->whereNotNull('social_account_id')->with(['socialAccount', 'workspace'])->find($data['post_id']);
        if (! $post instanceof Post || $post->workspace === null) {
            return $this->refuse(404, 'not_found');
        }

        $actor = CrmContent::actor($post->workspace, $data['actor_email']);
        if ($actor === null) {
            return $this->refuse(403, 'unknown_person');
        }
        if ($post->user_id !== $actor->id && $post->workspace->user_id !== $actor->id && ! $actor->isWorkspaceAdmin($post->workspace)) {
            return $this->refuse(403, 'forbidden', 'Only the person who wrote this post, or an owner or admin of the workspace, can delete it.');
        }

        $livePost = $post->status === PostStatus::Published || ($post->status === PostStatus::Publishing && filled($post->platform_post_id));
        if (! $livePost) {
            return $this->refuse(422, 'not_published', 'That post is not published yet, so there is nothing to delete.');
        }

        // The network already reports it gone (deleted there by hand): only the Engine's own record is left to settle.
        if ($post->publish_status !== PublishStatus::Deleted) {
            try {
                $network->delete($post);
            } catch (CrmDeleteRefused $e) {
                return $this->refuse($e->httpStatus, $e->errorCode, $e->getMessage());
            }
        }

        $platform = $post->platform?->network();
        $post->writePublication(['publish_status' => PublishStatus::Deleted, 'deleted_at' => now(), 'deleted_by' => $actor->id]);

        return response()->json(['data' => ['deleted' => true, 'platform' => $platform]])->header('Cache-Control', 'no-store, private');
    }

    private function refuse(int $status, string $code, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['error' => $code, 'message' => $message], fn ($v): bool => $v !== null), $status)->header('Cache-Control', 'no-store, private');
    }
}
