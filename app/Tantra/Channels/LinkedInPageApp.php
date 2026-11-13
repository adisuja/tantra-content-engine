<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

/**
 * tantra: LinkedIn company pages on their own developer app (Adi, 2026-11-14).
 *
 * LinkedIn only grants Community Management to an app that has no other
 * product, so company pages need an app of their own: the `linkedin_page`
 * entry in Settings > Connectors > Channels, written to
 * `services.linkedin-page`. While that app has no keys it falls back to the
 * profile app, and the LinkedIn card keeps its single consent screen.
 *
 * With a separate page app, a fresh LinkedIn connect asks for the profile
 * first, then sends the person on to the page app's consent screen and offers
 * both identities together. A reconnect goes straight to the right app.
 * The page app has no OpenID product, so its code is exchanged here and the
 * pages are read with the page token alone.
 */
final class LinkedInPageApp
{
    public const string PROFILE = 'profile';

    public const string PAGE = 'page';

    public const string PROFILE_THEN_PAGE = 'profile_then_page';

    /** The issuing app stamped on an account (meta.oauth_app). */
    public const string APP_PROFILE = 'linkedin';

    public const string APP_PAGE = 'linkedin_page';

    private const string KIND_KEY = 'tantra_linkedin_kind';

    private const string STATE_KEY = 'tantra_linkedin_page_state';

    private const string STASH_KEY = 'tantra_linkedin_profile_identities';

    /** OpenID and member scopes the page app cannot grant. */
    private const array PROFILE_ONLY_SCOPES = ['openid', 'profile', 'email', 'w_member_social'];

    /**
     * Whether pages use keys of their own, different from the profile app.
     */
    public static function separate(): bool
    {
        $page = (string) config('services.linkedin-page.client_id');

        return $page !== '' && $page !== (string) config('services.linkedin.client_id');
    }

    /**
     * The page app's keys, read at the point of use: the LinkedIn app's while
     * the page app has none (whatever the boot-time config holds).
     *
     * @return array{client_id: ?string, client_secret: ?string}
     */
    public static function pageKeys(): array
    {
        $id = config('services.linkedin-page.client_id');
        $secret = config('services.linkedin-page.client_secret');

        return filled($id) && filled($secret)
            ? ['client_id' => (string) $id, 'client_secret' => (string) $secret]
            : ['client_id' => config('services.linkedin.client_id'), 'client_secret' => config('services.linkedin.client_secret')];
    }

    /**
     * The keys a LinkedIn account refreshes with: those of the app that issued
     * its token (meta.oauth_app, stamped at connect). An account connected
     * before the stamp existed was issued by the LinkedIn profile app.
     *
     * @return array{client_id: ?string, client_secret: ?string}
     */
    public static function refreshKeys(SocialAccount $account): array
    {
        return data_get($account->meta, 'oauth_app') === self::APP_PAGE
            ? self::pageKeys()
            : ['client_id' => config('services.linkedin.client_id'), 'client_secret' => config('services.linkedin.client_secret')];
    }

    /**
     * Records on a connect identity which app issued its token.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    public static function stamp(array $identity, string $app): array
    {
        data_set($identity, 'attributes.meta.oauth_app', $app);

        return $identity;
    }

    /**
     * Which consent screen a connect starts with, or null for the single
     * combined screen (no separate page app).
     */
    public static function kindFor(Request $request, ?Platform $reconnecting): ?string
    {
        if (! self::separate()) {
            return null;
        }

        $person = Platform::LinkedIn->isEnabled();
        $pages = Platform::LinkedInPage->isEnabled();

        return match (true) {
            $reconnecting === Platform::LinkedInPage, $request->query('kind') === self::PAGE, ! $person => self::PAGE,
            $reconnecting === Platform::LinkedIn, $request->query('kind') === self::PROFILE, ! $pages => self::PROFILE,
            default => self::PROFILE_THEN_PAGE,
        };
    }

    /**
     * Where the connect goes: the profile app through Socialite, or the page app's authorize URL.
     */
    public static function redirectUrl(string $kind): string
    {
        session([self::KIND_KEY => $kind]);

        if ($kind !== self::PAGE) {
            return Socialite::driver('linkedin-openid')
                ->scopes(config('trypost.platforms.linkedin.scopes'))
                ->redirect()
                ->getTargetUrl();
        }

        $state = Str::random(40);
        session([self::STATE_KEY => $state]);

        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::pageKeys()['client_id'],
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'scope' => implode(' ', self::pageScopes()),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The kind the callback finishes, read once.
     */
    public static function pullKind(): ?string
    {
        $kind = session()->pull(self::KIND_KEY);

        return is_string($kind) ? $kind : null;
    }

    /**
     * Company page scopes without the OpenID and member ones.
     *
     * @return list<string>
     */
    public static function pageScopes(): array
    {
        return array_values(array_diff((array) config('trypost.platforms.linkedin-page.scopes'), self::PROFILE_ONLY_SCOPES));
    }

    /**
     * Exchanges the page app's code for a token, after checking the state.
     *
     * @return array{access_token: string, refresh_token: ?string, token_expires_at: mixed, scopes: list<string>}
     */
    public static function credentials(Request $request): array
    {
        $state = session()->pull(self::STATE_KEY);
        if (! is_string($state) || $state === '' || ! hash_equals($state, (string) $request->query('state'))) {
            throw new RuntimeException('LinkedIn page connection state did not match.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            throw new RuntimeException('LinkedIn sent no authorization code.');
        }

        $response = Http::asForm()->acceptJson()->post(config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            ...self::pageKeys(),
        ]);

        $token = $response->json('access_token');
        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new RuntimeException('LinkedIn page token exchange failed: '.$response->status());
        }

        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $token,
            'refresh_token' => $response->json('refresh_token'),
            'token_expires_at' => is_numeric($expiresIn) ? now()->addSeconds((int) $expiresIn) : null,
            'scopes' => array_values(array_filter(preg_split('/[\s,]+/', (string) $response->json('scope', '')) ?: [])),
        ];
    }

    /**
     * Keeps the profile identity while the person visits the page app.
     *
     * @param  list<array<string, mixed>>  $identities
     */
    public static function stash(array $identities): void
    {
        session([self::STASH_KEY => $identities]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pullStash(): array
    {
        $stash = session()->pull(self::STASH_KEY, []);

        return is_array($stash) ? array_values($stash) : [];
    }

    private static function redirectUri(): string
    {
        return (string) (config('services.linkedin-page.redirect') ?: url('accounts/linkedin/callback'));
    }
}
