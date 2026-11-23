<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Actions\Post\DeleteChannelPosts;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Actions\SocialAccount\ListPinterestBoards;
use App\Actions\SocialAccount\ReorderSocialAccounts;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Post\QueueBusyException;
use App\Exceptions\SocialAccount\ConnectFlowException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Http\Controllers\Auth\SocialConnectionController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TikTokCreatorInfoResource;
use App\Models\SocialAccount;
use App\Services\Social\TikTokCreatorInfo;
use App\Support\Social\PendingConnection;
use App\Support\ThreadReplies;
use App\Tantra\Channels\AccountPicker;
use App\Tantra\Channels\AccountPickerException;
use App\Tantra\Channels\ConnectFailures;
use App\Tantra\Channels\CrmConnectLink;
use App\Tantra\Composer\TantraMetaRules;
use App\Tantra\Notify\Mirror\SendContentChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * tantra: One Content app PRD 4.1, channel care over the signed gateway, for the CRM's Content > Channels and composer.
 *
 *   GET    /api/gateway/tantra/connect/{token}/candidates  the accounts a sign in offered (AccountPicker), never their tokens
 *   POST   /api/gateway/tantra/connect/{token}/select      {identities: [key]}: connect them (201)
 *   POST   /api/gateway/tantra/connect/bluesky             {identifier, password}: an app password; connects at once (201)
 *   POST   /api/gateway/tantra/connect/mastodon            {instance}: answers {url}, a signed link that signs in and starts the
 *                                                          server's own sign in; it comes back to the CRM's account picker
 *   PUT    /api/gateway/tantra/channels/order              {ids: [channel id]}: the order channels show in
 *   DELETE /api/gateway/tantra/channels/{account}          disconnect a channel (its unpublished posts go with it)
 *   GET    /api/gateway/tantra/channels/{account}/options  what the composer may set for it: Pinterest boards, YouTube title and
 *                                                          playlist, TikTok privacy, Threads reply chain
 */
class GatewayChannelsController extends Controller
{
    public function candidates(Request $request, string $token): JsonResponse
    {
        $kept = AccountPicker::load($token, $request->user());
        abort_if($kept === null, Response::HTTP_NOT_FOUND, 'This sign in has expired. Connect the channel again.');

        return response()->json(['data' => AccountPicker::candidates($kept)]);
    }

    public function select(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['identities' => ['required', 'array', 'min:1'], 'identities.*' => ['required', 'string', 'distinct', 'max:300']]);
        $kept = AccountPicker::load($token, $request->user());
        abort_if($kept === null, Response::HTTP_NOT_FOUND, 'This sign in has expired. Connect the channel again.');

        try {
            $channels = AccountPicker::select($token, $request->user(), $kept, array_values($data['identities']));
        } catch (AccountPickerException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ConnectFlowException|NetworkAlreadyConnectedException $e) {
            return response()->json(['message' => __("accounts.connect.errors.{$e->messageKey}"), 'code' => $e->messageKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['message' => 'Connected.', 'data' => $channels], Response::HTTP_CREATED);
    }

    public function connectBluesky(Request $request): JsonResponse
    {
        $request->validate(['identifier' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:3', 'max:255']]);
        $network = SocialConnectionController::networkFor(Platform::Bluesky);
        PendingConnection::forget();

        // The engine's own Bluesky sign in (BlueskyController::store) leaves the offered account as the pending connection.
        $network->store($request);
        $pending = PendingConnection::current();
        if (! $pending?->isReady()) {
            $reason = $pending?->failure();
            PendingConnection::forget();

            return response()->json([
                'message' => ConnectFailures::reason(Platform::Bluesky, $reason) ?? __('accounts.connect.errors.'.($reason ?? 'error_connecting')),
                'code' => $reason ?? 'error_connecting',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $keys = array_values(array_diff($pending->identityKeys(), $pending->lockedIdentityKeys()));
        if ($keys === []) {
            PendingConnection::forget();

            return response()->json(['message' => __('accounts.connect.errors.identity_connected'), 'code' => 'identity_connected'], Response::HTTP_CONFLICT);
        }

        try {
            $accounts = $network->finishPicked($request->user(), $keys);
        } catch (ConnectFlowException|NetworkAlreadyConnectedException $e) {
            return response()->json(['message' => __("accounts.connect.errors.{$e->messageKey}"), 'code' => $e->messageKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['message' => 'Connected.', 'data' => $accounts->map(fn (SocialAccount $a): array => SendContentChange::channel($a))->values()], Response::HTTP_CREATED);
    }

    public function connectMastodon(Request $request): JsonResponse
    {
        $data = $request->validate(['instance' => ['required', 'string', 'max:255', 'url:https']]);
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $this->authorize('connectAccounts', $workspace);
        abort_unless(Platform::Mastodon->isEnabled(), Response::HTTP_FORBIDDEN, 'Mastodon is not available on this engine.');

        $url = CrmConnectLink::signedUrl($user, $workspace, 'mastodon', 'https://'.CrmConnectLink::RETURN_HOST.'/content/channels', rtrim($data['instance'], '/'));

        return response()->json(['data' => ['url' => $url, 'expires_in' => CrmConnectLink::TTL_MINUTES * 60]]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('manageAccounts', $workspace);

        ReorderSocialAccounts::execute($workspace, array_values($data['ids']));

        return response()->json(['message' => 'Saved.', 'data' => $workspace->socialAccounts()->orderBy('position')->pluck('id')->values()]);
    }

    public function disconnect(Request $request, string $account): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('manageAccounts', $workspace);
        $channel = $this->channel($request, $account);

        try {
            ReflowChannelQueue::withLock([$channel->id], fn () => DB::transaction(function () use ($channel): void {
                DeleteChannelPosts::forAccount($channel);
                $channel->delete();
            }));
        } catch (QueueBusyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'queue_busy'], Response::HTTP_CONFLICT);
        }

        return response()->json(['message' => 'Disconnected.']);
    }

    public function options(Request $request, TikTokCreatorInfo $tiktok, string $account): JsonResponse
    {
        $channel = $this->channel($request, $account);
        $this->authorize('view', $channel);
        $platform = $channel->platform;

        $options = match ($platform) {
            Platform::Pinterest => ['boards' => $this->attempt(fn () => ListPinterestBoards::execute($channel))],
            Platform::YouTube => [
                'title' => ['required' => true, 'max' => 100],
                // The engine adds the video to a playlist by its id; it has no playlist list of its own.
                'playlist' => ['field' => 'playlist_id', 'max' => 128],
                'tags_max_total' => TantraMetaRules::YOUTUBE_TAGS_MAX_TOTAL,
            ],
            Platform::TikTok => ['creator' => $this->attempt(fn () => (new TikTokCreatorInfoResource($tiktok->interactive()->fetchOrFail($channel)))->resolve($request))],
            Platform::Threads => [
                'reply_control' => TantraMetaRules::REPLY_CONTROL,
                'reply_chain' => ['supported' => ThreadReplies::supports($platform), 'max_replies' => ThreadReplies::maxReplies($platform)],
            ],
            default => [],
        };

        return response()->json(['data' => [
            'id' => $channel->id,
            'platform' => $platform->value,
            'network' => $platform->network(),
            'max_length' => $platform->maxContentLength(),
            'thread_replies' => ThreadReplies::supports($platform),
            ...$options,
        ]]);
    }

    private function channel(Request $request, string $account): SocialAccount
    {
        $row = Str::isUuid($account) ? $request->user()->currentWorkspace->socialAccounts()->find($account) : null;
        abort_if($row === null, Response::HTTP_NOT_FOUND);

        return $row;
    }

    /** A network's answer, or {error} when it could not be read right now (the composer still opens). */
    private function attempt(callable $read): mixed
    {
        try {
            $value = $read();

            return $value instanceof \JsonSerializable ? $value->jsonSerialize() : $value;
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
