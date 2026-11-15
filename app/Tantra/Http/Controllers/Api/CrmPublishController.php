<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Post\CreatePosts;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Tantra\Crm\CrmContent;
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

    private function refuse(int $status, string $code, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['error' => $code, 'message' => $message], fn ($v): bool => $v !== null), $status);
    }
}
