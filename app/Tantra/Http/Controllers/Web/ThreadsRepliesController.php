<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Tantra\Social\EngagementScopes;
use App\Tantra\Social\ThreadsNeedsReconnect;
use App\Tantra\Social\ThreadsReplies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * tantra: the Replies panel on a Threads channel's Insights page.
 */
final class ThreadsRepliesController extends Controller
{
    public function index(Request $request, SocialAccount $account): JsonResponse
    {
        $workspace = $this->authorizeAccount($request, $account);
        $this->authorize('view', $workspace);

        if (! $this->usable($account, 'threads_read_replies')) {
            return $this->needsReconnect($account);
        }

        try {
            $this->refresh($account);

            return response()->json(['posts' => ThreadsReplies::recent($account)]);
        } catch (ThreadsNeedsReconnect) {
            return $this->needsReconnect($account);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['posts' => [], 'failed' => true]);
        }
    }

    public function store(Request $request, SocialAccount $account): JsonResponse
    {
        $workspace = $this->authorizeAccount($request, $account);
        $this->authorize('createPost', $workspace);

        $data = $request->validate([
            'reply_to_id' => ['required', 'string', 'regex:/^[0-9]{1,32}$/'],
            'text' => ['required', 'string', 'max:500'],
        ]);

        if (! $this->usable($account, 'threads_manage_replies')) {
            return $this->needsReconnect($account, Response::HTTP_CONFLICT);
        }

        try {
            $this->refresh($account);

            // Only replies under this channel's own posts, the ones the panel shows.
            if (! ThreadsReplies::answerable($account, $data['reply_to_id'])) {
                return response()->json(['message' => __('tantra.threads_replies.not_found')], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json(ThreadsReplies::reply($account, $data['reply_to_id'], trim($data['text'])));
        } catch (ThreadsNeedsReconnect) {
            return $this->needsReconnect($account, Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('tantra.threads_replies.failed')], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function authorizeAccount(Request $request, SocialAccount $account): Workspace
    {
        $workspace = $request->user()->currentWorkspace;

        abort_unless(
            $workspace && $account->workspace_id === $workspace->id && $account->platform === Platform::Threads,
            Response::HTTP_FORBIDDEN,
        );

        return $workspace;
    }

    /**
     * A disconnected channel, or one connected before the reply scopes were asked
     * for, must reconnect. A channel with no recorded scopes is tried.
     */
    private function usable(SocialAccount $account, string $scope): bool
    {
        if ($account->status === Status::Disconnected || blank($account->access_token)) {
            return false;
        }

        $scopes = (array) ($account->scopes ?? []);

        return $scopes === [] ? EngagementScopes::enabled(Platform::Threads) : in_array($scope, $scopes, true);
    }

    private function needsReconnect(SocialAccount $account, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'posts' => [],
            'needs_reconnect' => true,
            'message' => __('tantra.threads_replies.needs_reconnect'),
            'reconnect_url' => route('app.social.threads.connect', [
                'reconnect' => $account->id,
                'return_to' => route('app.channels.insights', $account, false),
            ]),
        ], $status);
    }

    private function refresh(SocialAccount $account): void
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }
    }
}
