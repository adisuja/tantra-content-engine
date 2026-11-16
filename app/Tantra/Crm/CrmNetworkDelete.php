<?php

declare(strict_types=1);

namespace App\Tantra\Crm;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Exceptions\PlatformUnavailableException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\TokenRedactor;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * tantra: deletes a published post on its network, through the credentials the Engine already holds for the channel (D33,
 * tantra-crm docs/crm/engine-patches/d33-delete-post/README.md). Returns when the post is gone (a post the network no longer
 * has counts as gone); throws CrmDeleteRefused with the answer to give otherwise. Nothing here ever puts a token, a provider
 * body or a stack trace into a message: those go to the log only, with tokens redacted.
 *
 * Wired: LinkedIn (personal and page, direct token), LinkedIn through Unipile, X, Bluesky, Threads, Facebook pages.
 * Instagram has no delete in the Graph API; every other network is not wired yet. Both answer delete_not_supported.
 */
final class CrmNetworkDelete
{
    /** The Posts API version the publisher sends (AbstractLinkedInPublisher::$apiVersion). */
    private const string LINKEDIN_VERSION = '202601';

    private const string BLUESKY_DELETE = 'com.atproto.repo.deleteRecord';

    /** Graph API errors: an expired or revoked token, and a missing permission. */
    private const int GRAPH_TOKEN = 190;

    private const array GRAPH_PERMISSION = [3, 10, 200, 283];

    /** @throws CrmDeleteRefused */
    public function delete(Post $post): void
    {
        $platform = $post->platform;
        $network = self::networkName($platform);
        $id = trim((string) ($platform === Platform::UnipileLinkedIn ? (data_get($post->meta, 'unipile_post_id') ?: $post->platform_post_id) : $post->platform_post_id));

        $wired = [Platform::LinkedIn, Platform::LinkedInPage, Platform::UnipileLinkedIn, Platform::X, Platform::Bluesky, Platform::Threads, Platform::Facebook];
        if (! in_array($platform, $wired, true) || $id === '' || ($this->isLinkedInDirect($platform) && ! str_starts_with($id, 'urn:li:'))) {
            throw self::unsupported($network);
        }

        $account = $post->socialAccount;
        if (! $account instanceof SocialAccount || $account->status !== AccountStatus::Connected) {
            throw self::notConnected($account);
        }

        try {
            if ($platform !== Platform::UnipileLinkedIn && $account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }
        } catch (Throwable $e) {
            Log::warning('tantra: delete post, token refresh failed', ['post_id' => $post->id, 'platform' => $platform?->value, 'error' => TokenRedactor::redact($e->getMessage())]);
            throw self::notConnected($account);
        }

        try {
            $response = match ($platform) {
                Platform::LinkedIn, Platform::LinkedInPage => $this->linkedIn($platform, $account, $id),
                Platform::UnipileLinkedIn => $this->unipile($account, $id),
                Platform::X => $this->http()->withToken((string) $account->access_token)->delete(rtrim((string) config('trypost.platforms.x.api'), '/').'/tweets/'.rawurlencode($id)),
                Platform::Bluesky => $this->bluesky($account, $id),
                Platform::Threads => $this->http()->withQueryParameters(['access_token' => $account->access_token])->delete(rtrim((string) config('trypost.platforms.threads.graph_api'), '/').'/'.rawurlencode($id)),
                Platform::Facebook => $this->http()->withQueryParameters(['access_token' => $account->access_token])->delete(rtrim((string) config('trypost.platforms.facebook.graph_api'), '/').'/'.rawurlencode($id)),
                default => throw self::unsupported($network),
            };
        } catch (CrmDeleteRefused $e) {
            throw $e;
        } catch (HttpClientException|PlatformUnavailableException $e) {
            Log::warning('tantra: delete post, network unreachable', ['post_id' => $post->id, 'platform' => $platform->value, 'error' => TokenRedactor::redact($e->getMessage())]);
            throw self::upstream($network);
        }

        $this->judge($response, $post, $account, $network);
    }

    private function isLinkedInDirect(?Platform $platform): bool
    {
        return in_array($platform, [Platform::LinkedIn, Platform::LinkedInPage], true);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(20);
    }

    private function linkedIn(Platform $platform, SocialAccount $account, string $urn): Response
    {
        return $this->http()->withToken((string) $account->access_token)->withHeaders([
            'X-Restli-Protocol-Version' => '2.0.0',
            'X-RestLi-Method' => 'DELETE',
            'LinkedIn-Version' => self::LINKEDIN_VERSION,
        ])->delete(rtrim((string) config("trypost.platforms.{$platform->value}.api"), '/').'/rest/posts/'.rawurlencode($urn));
    }

    private function unipile(SocialAccount $account, string $postId): Response
    {
        $accountId = (string) $account->platform_user_id;
        if ($accountId === '' || ! UnipileClient::configured()) {
            throw self::notConnected($account);
        }

        return app(UnipileClient::class)->deletePost($accountId, $postId);
    }

    private function bluesky(SocialAccount $account, string $id): Response
    {
        return $this->http()->withToken((string) $account->access_token)->post(BlueskyLexicon::xrpc($account->bluesky_service, self::BLUESKY_DELETE), [
            'repo' => $account->platform_user_id,
            'collection' => BlueskyLexicon::FEED_POST,
            'rkey' => basename($id), // the publisher stores the record key; an at-uri works too
        ]);
    }

    /** @throws CrmDeleteRefused */
    private function judge(Response $response, Post $post, SocialAccount $account, string $network): void
    {
        $status = $response->status();
        $body = $response->json();
        $deleted = data_get($body, 'data.deleted');

        if ($response->successful()) {
            if ($deleted === false) { // X answers 200 {"data":{"deleted":false}} when it did not delete
                Log::warning('tantra: delete post, network did not delete', ['post_id' => $post->id, 'platform' => $post->platform?->value]);
                throw self::unsupported($network);
            }

            return;
        }

        $graph = data_get($body, 'error.code');
        $graph = is_numeric($graph) ? (int) $graph : null;
        $sub = data_get($body, 'error.error_subcode');
        $sub = is_numeric($sub) ? (int) $sub : null;
        $atproto = data_get($body, 'error');

        Log::warning('tantra: delete post refused by the network', ['post_id' => $post->id, 'platform' => $post->platform?->value, 'status' => $status, 'body' => TokenRedactor::redact(mb_substr($response->body(), 0, 500))]);

        if (in_array($status, [404, 410], true) || ($graph === 100 && $sub === 33)) {
            return; // already gone: the goal is that the post is gone
        }
        if ($status === 401 || $graph === self::GRAPH_TOKEN || in_array($atproto, ['ExpiredToken', 'InvalidToken', 'AuthenticationRequired'], true)) {
            throw self::notConnected($account);
        }
        if ($status === 403 || in_array($graph, self::GRAPH_PERMISSION, true)) {
            throw new CrmDeleteRefused(422, 'delete_not_supported', "{$network} did not allow the delete with the permissions it has now. Connect the channel again in Content, or delete the post on the network itself.");
        }
        if ($status === 429 || $status >= 500) {
            throw self::upstream($network);
        }

        throw self::unsupported($network);
    }

    public static function networkName(?Platform $platform): string
    {
        return match ($platform?->network()) {
            'linkedin' => 'LinkedIn',
            'x' => 'X',
            'bluesky' => 'Bluesky',
            'threads' => 'Threads',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            'pinterest' => 'Pinterest',
            'mastodon' => 'Mastodon',
            'telegram' => 'Telegram',
            'discord' => 'Discord',
            'google_business' => 'Google Business',
            default => 'This network',
        };
    }

    private static function unsupported(string $network): CrmDeleteRefused
    {
        return new CrmDeleteRefused(422, 'delete_not_supported', "{$network} does not let a post be deleted from here. Delete it on the network itself.");
    }

    private static function upstream(string $network): CrmDeleteRefused
    {
        return new CrmDeleteRefused(502, 'upstream', "{$network} is not answering. Try again in a minute.");
    }

    private static function notConnected(?SocialAccount $account): CrmDeleteRefused
    {
        $name = $account !== null ? (string) ($account->display_name ?: $account->username) : '';

        return new CrmDeleteRefused(409, 'channel_not_connected', ($name !== '' ? $name : 'The channel').' is not connected any more. Connect it again in Content, then delete the post.');
    }
}
