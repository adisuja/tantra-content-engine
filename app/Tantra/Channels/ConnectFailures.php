<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\SocialAccount\ConnectFlowException;
use App\Models\User;
use App\Services\Social\TokenRedactor;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * tantra: why a connection stopped, in words the person can act on.
 *
 * Upstream ends most failures as "error_connecting". This tells the common
 * ones apart (the network's own error on the callback, a spent or forged
 * state, an expired code, a rate limit, an outage, app keys or settings the
 * network refused, an account that is not a tester of an app in development)
 * so the confirmation page can say what happened and what to do next. The
 * keys go through upstream's PendingConnection::fail() like any other.
 */
final class ConnectFailures
{
    public const string GENERIC = 'error_connecting';

    public const string INVALID_STATE = 'invalid_state';

    public const string CODE_EXPIRED = 'code_expired';

    public const string RATE_LIMITED = 'rate_limited';

    public const string PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const string PROVIDER_ERROR = 'provider_error';

    public const string NOT_A_TESTER = 'not_a_tester';

    public const string APP_SETUP = 'app_setup';

    public const string APP_KEYS_REJECTED = 'app_keys_rejected';

    public const string ORG_BLOCKED = 'org_blocked';

    public const string INSTAGRAM_PERSONAL = 'instagram_personal';

    public const string GBP_ACCESS_PENDING = 'gbp_access_pending';

    public const string DISCORD_NO_SERVER = 'discord_no_server';

    /** Failures an account that is not a tester of an app in development can cause. */
    private const array TESTING_SUSPECTS = [self::NOT_A_TESTER, self::PROVIDER_ERROR, self::GENERIC, self::APP_SETUP, 'failed_to_authenticate'];

    /** Failures only the person who looks after the developer app can fix. */
    private const array OWNER_FIXES = [self::APP_SETUP, self::APP_KEYS_REJECTED, self::GBP_ACCESS_PENDING];

    /** Meta's "the user has not accepted the invite to test the app" (Threads, Facebook Login). */
    private const array META_TESTER_CODES = ['1349245'];

    private const string DETAIL_KEY = 'tantra_connect_detail';

    /**
     * The network's error on the callback (`error`, `error_code`, with
     * `error_description` or `error_message`), or null when there is none.
     * Cancels are upstream's (SocialController::consentCancelled()) and are
     * checked before this.
     */
    public static function fromCallback(Request $request, Platform $platform): ?string
    {
        $error = trim((string) $request->query('error', ''));
        $code = trim((string) $request->query('error_code', ''));
        if ($error === '' && $code === '') {
            return null;
        }

        $message = trim((string) ($request->query('error_description') ?? $request->query('error_message') ?? ''));
        self::remember($platform, $message !== '' ? $message : ($error !== '' ? $error : $code));

        return self::fromProviderCode($error, $code, $message);
    }

    /** A failed HTTP answer from the network, during the token exchange or a profile read. */
    public static function fromResponse(Response $response, Platform $platform, string $fallback = self::GENERIC): string
    {
        return self::fromHttp($response->status(), (string) $response->body(), $platform, $fallback);
    }

    /** Whatever a callback's try block caught. */
    public static function fromException(Throwable $e, Platform $platform, string $fallback = self::GENERIC): string
    {
        return match (true) {
            $e instanceof ConnectFlowException => $e->messageKey,
            $e instanceof InvalidStateException => self::INVALID_STATE,
            $e instanceof ConnectionException, $e instanceof GuzzleConnectException => self::PROVIDER_UNAVAILABLE,
            $e instanceof RequestException => self::fromResponse($e->response, $platform, $fallback),
            $e instanceof GuzzleRequestException && $e->getResponse() !== null => self::fromHttp(
                $e->getResponse()->getStatusCode(),
                (string) $e->getResponse()->getBody(),
                $platform,
                $fallback,
            ),
            $e instanceof SocialPublishException => self::fromCategory($e, $platform, $fallback),
            // App\Socialite\DiscordProvider's words when the bot was not added to a server.
            $platform === Platform::Discord && str_contains($e->getMessage(), 'did not include a server') => self::DISCORD_NO_SERVER,
            default => $fallback,
        };
    }

    /**
     * What the confirmation page adds under the reason: the next step, the
     * network's own words, a link that helps, and the way to tester access
     * when the network's app is still in development.
     *
     * @return array{network: string, next: string, detail: string|null, link: array{label: string, url: string}|null, testing_url: string|null, owner_url: string|null}|null
     */
    public static function present(Platform $platform, ?string $failure, string $state, ?User $user): ?array
    {
        if ($state === 'select') {
            return null;
        }
        $network = self::networkName($platform);
        $key = match ($state) {
            'cancelled', 'expired', 'missing_permission' => $state,
            default => (string) $failure,
        };
        $next = __("tantra.connect.next.{$key}", ['network' => $network]);
        if ($next === "tantra.connect.next.{$key}") {
            $next = __('tantra.connect.next.default', ['network' => $network]);
        }

        $app = ChannelApps::appFor($platform->value);
        $testing = $state === 'error' && $app !== null && ChannelApps::enforced() && ! ChannelApps::isLive($platform->value)
            && in_array($failure, self::TESTING_SUSPECTS, true);
        $canManage = ChannelApps::canManage($user);

        return [
            'network' => $network,
            'next' => $next,
            'detail' => $state === 'error' ? self::detail($platform) : null,
            'link' => self::helpLink((string) $failure),
            'testing_url' => $testing ? PreConnect::prepareUrl($platform) : null,
            'owner_url' => $canManage && $app !== null && in_array($failure, self::OWNER_FIXES, true) ? PreConnect::settingsUrl($app) : null,
        ];
    }

    /**
     * The reason line, for the keys Tantra adds or words better. Null leaves
     * upstream's own `accounts.connect.errors.*` line.
     */
    public static function reason(Platform $platform, ?string $failure): ?string
    {
        if ($failure === null) {
            return null;
        }
        $line = __("tantra.connect.errors.{$failure}", ['network' => self::networkName($platform)]);
        if ($line !== "tantra.connect.errors.{$failure}") {
            return $line;
        }

        // A key nobody wrote copy for must never show as a raw key.
        return __("accounts.connect.errors.{$failure}") === "accounts.connect.errors.{$failure}"
            ? __('tantra.connect.errors.unknown', ['network' => self::networkName($platform)])
            : null;
    }

    /** The network's name as people say it: "Facebook", not "Facebook Page". */
    public static function networkName(Platform $platform): string
    {
        $network = $platform === Platform::InstagramFacebook ? 'instagram' : $platform->network();
        $line = __("tantra.connect.networks.{$network}");

        return $line === "tantra.connect.networks.{$network}" ? $platform->label() : $line;
    }

    /**
     * Bluesky signs in with a form, not a redirect: what its refusal means
     * when it is not simply a wrong app password. Null keeps upstream's
     * "Invalid credentials."
     */
    public static function blueskySignIn(Response $response): ?string
    {
        $error = (string) $response->json('error', '');

        return match (true) {
            $response->status() === 429 || $error === 'RateLimitExceeded' => __('tantra.connect.bluesky.rate_limited'),
            $error === 'AuthFactorTokenRequired' => __('tantra.connect.bluesky.two_factor'),
            in_array($error, ['AccountTakedown', 'AccountDeactivated'], true) => __('tantra.connect.bluesky.suspended'),
            $response->status() >= 500 => __('tantra.connect.bluesky.unavailable'),
            default => null,
        };
    }

    /** Forget the network's words from an earlier attempt (a new connect is starting). */
    public static function forgetDetail(): void
    {
        session()->forget(self::DETAIL_KEY);
    }

    private static function fromProviderCode(string $error, string $code, string $message): string
    {
        $error = Str::lower($error);

        return match (true) {
            in_array($code, self::META_TESTER_CODES, true),
            (bool) preg_match('/\b(tester|testers|test users?|invite to test|development mode|sandbox|target users?|not accepted the invite|app not active|non_sandbox_target)\b/i', $message.' '.$error) => self::NOT_A_TESTER,
            in_array($error, ['invalid_client', 'invalid_client_id'], true) => self::APP_KEYS_REJECTED,
            in_array($error, ['invalid_scope', 'unauthorized_scope_error', 'unauthorized_client', 'unsupported_response_type', 'redirect_uri_mismatch', 'invalid_redirect_uri'], true) => self::APP_SETUP,
            in_array($error, ['admin_policy_enforced', 'org_internal', 'access_denied_by_policy'], true) => self::ORG_BLOCKED,
            in_array($error, ['temporarily_unavailable', 'server_error'], true) => self::PROVIDER_UNAVAILABLE,
            in_array($error, ['rate_limit_exceeded', 'too_many_requests', 'slow_down'], true) => self::RATE_LIMITED,
            default => self::PROVIDER_ERROR,
        };
    }

    private static function fromHttp(int $status, string $body, Platform $platform, string $fallback): string
    {
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];
        $error = data_get($json, 'error');
        $text = Str::lower(implode(' ', array_filter([
            is_string($error) ? $error : null,
            data_get($json, 'error_description'),
            data_get($json, 'error.message'),
            data_get($json, 'error.status'),
            data_get($json, 'error.errors.0.reason'),
            data_get($json, 'error_message'),
            data_get($json, 'message'),
            $json !== [] ? null : $body,
        ], is_string(...))));
        $metaCode = (int) data_get($json, 'error.code', 0);

        $message = data_get($json, 'error_description') ?? data_get($json, 'error.message') ?? data_get($json, 'error_message') ?? data_get($json, 'message');
        if (is_string($message) && $message !== '') {
            self::remember($platform, $message);
        }

        // Bluesky and Mastodon have no developer app of ours: their settings and
        // keys are not something the owner can fix.
        $ownApp = ChannelApps::appFor($platform->value) !== null;

        $rateLimited = $status === 429
            || in_array($metaCode, [4, 17, 32, 613], true)
            || Str::contains($text, ['resource_exhausted', 'ratelimitexceeded', 'userratelimitexceeded', 'quotaexceeded', 'rate limit', 'too many requests']);

        return match (true) {
            $platform === Platform::GoogleBusiness && ($rateLimited || Str::contains($text, ['permission_denied'])) => self::GBP_ACCESS_PENDING,
            $rateLimited => self::RATE_LIMITED,
            $status >= 500 => self::PROVIDER_UNAVAILABLE,
            $ownApp && Str::contains($text, ['service_disabled', 'has not been used in project', 'accessnotconfigured', 'redirect_uri', 'redirect uri', "can't load url", 'app not set up']) => self::APP_SETUP,
            $ownApp && Str::contains($text, ['invalid_client', 'client secret', 'client_secret', 'invalid client', 'client authentication failed']) => self::APP_KEYS_REJECTED,
            Str::contains($text, ['invalid_grant', 'code has expired', 'code expired', 'expired authorization code', 'authorization code has been used', 'code was already redeemed', 'code has been used']) => self::CODE_EXPIRED,
            Str::contains($text, ['tester', 'development mode', 'sandbox', 'not accepted the invite']) => self::NOT_A_TESTER,
            default => $fallback,
        };
    }

    private static function fromCategory(SocialPublishException $e, Platform $platform, string $fallback): string
    {
        return match (true) {
            $platform === Platform::GoogleBusiness && in_array($e->category, [ErrorCategory::RateLimit, ErrorCategory::Permission], true) => self::GBP_ACCESS_PENDING,
            $e->category === ErrorCategory::RateLimit => self::RATE_LIMITED,
            in_array($e->category, [ErrorCategory::ServerError, ErrorCategory::PlatformUnavailable, ErrorCategory::Timeout], true) => self::PROVIDER_UNAVAILABLE,
            default => $fallback,
        };
    }

    /** Keep the network's own words for the page, never a token. */
    private static function remember(Platform $platform, string $message): void
    {
        $clean = Str::limit(trim(strip_tags((string) TokenRedactor::redact($message))), 200);
        if ($clean !== '') {
            session()->put(self::DETAIL_KEY, ['platform' => $platform->value, 'text' => $clean]);
        }
    }

    private static function detail(Platform $platform): ?string
    {
        $detail = session(self::DETAIL_KEY);

        return is_array($detail) && ($detail['platform'] ?? null) === $platform->value ? (string) $detail['text'] : null;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function helpLink(string $failure): ?array
    {
        $link = match ($failure) {
            'no_facebook_pages' => ['create_page', 'https://www.facebook.com/pages/create'],
            'no_facebook_instagram_pages' => ['linked_accounts', 'https://business.facebook.com/latest/settings/instagram_account'],
            'no_youtube_channels' => ['create_channel', 'https://www.youtube.com/create_channel'],
            'no_google_business_locations' => ['business_profile', 'https://business.google.com/'],
            self::INSTAGRAM_PERSONAL => ['switch_professional', 'https://www.instagram.com/accounts/edit/'],
            self::ORG_BLOCKED => ['org_admin', 'https://support.google.com/a/answer/7281227'],
            default => null,
        };

        return $link === null ? null : ['label' => __("tantra.connect.links.{$link[0]}"), 'url' => $link[1]];
    }
}
