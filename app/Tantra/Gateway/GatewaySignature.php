<?php

declare(strict_types=1);

namespace App\Tantra\Gateway;

use Illuminate\Http\Request;

/**
 * tantra: the signature the Tantra CRM Worker puts on every gateway call.
 *
 *   X-Tantra-Signature: t=<unix seconds>,kid=<secret id>,v1=<hex HMAC-SHA256(secret, signed string)>
 *   signed string = t.team.member.scopes.METHOD.path-and-query.sha256(raw body)
 *
 * team is X-Tantra-Workspace (the Tantra team id), member is X-Tantra-Member (the Clerk user id) and scopes is
 * X-Tantra-Scopes (comma separated). They are inside the signature, so a caller cannot change who it acts as or what
 * it may do. Five minute window; the signature covers one request body.
 *
 * M2: the Worker may also send X-Tantra-Member-Email (the member's Tantra email). When it does, the email is signed too:
 * the signed string gets ".<lowercased email>" on the end. A request without that header is signed exactly as before.
 * The engine uses the email only to set up a teammate it has never seen (AuthenticateTantraGateway).
 */
final class GatewaySignature
{
    public const WINDOW = 300;

    /**
     * @param  array<string, string>  $secrets  kid => secret
     * @return array{team: string, member: string, scopes: list<string>, email: ?string}|string the claims, or a short error code
     */
    public static function verify(Request $request, array $secrets, ?int $now = null): array|string
    {
        $header = (string) $request->header('X-Tantra-Signature', '');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = $parts['t'] ?? '';
        $kid = $parts['kid'] ?? '';
        $given = $parts['v1'] ?? '';
        if ($t === '' || ! ctype_digit($t) || $kid === '' || $given === '') {
            return 'unsigned';
        }
        if (abs(($now ?? time()) - (int) $t) > self::WINDOW) {
            return 'stale';
        }
        $secret = $secrets[$kid] ?? null;
        if ($secret === null) {
            return 'unknown_key';
        }
        $team = (string) $request->header('X-Tantra-Workspace', '');
        $member = (string) $request->header('X-Tantra-Member', '');
        $scopes = (string) $request->header('X-Tantra-Scopes', '');
        $email = mb_strtolower(trim((string) $request->header('X-Tantra-Member-Email', '')));
        $signed = implode('.', [$t, $team, $member, $scopes, strtoupper($request->method()), $request->getRequestUri(), hash('sha256', $request->getContent())])
            .($email !== '' ? '.'.$email : '');
        if (! hash_equals(hash_hmac('sha256', $signed, $secret), strtolower($given))) {
            return 'bad_signature';
        }

        return ['team' => $team, 'member' => $member, 'scopes' => array_values(array_filter(array_map('trim', explode(',', $scopes)))), 'email' => $email !== '' ? $email : null];
    }

    /** The header value for a request, for the tests and for any other caller that signs the same way. */
    public static function sign(string $secret, string $kid, int $t, string $team, string $member, string $scopes, string $method, string $pathAndQuery, string $body, ?string $email = null): string
    {
        $email = mb_strtolower(trim((string) $email));
        $signed = implode('.', [$t, $team, $member, $scopes, strtoupper($method), $pathAndQuery, hash('sha256', $body)]).($email !== '' ? '.'.$email : '');

        return "t={$t},kid={$kid},v1=".hash_hmac('sha256', $signed, $secret);
    }
}
