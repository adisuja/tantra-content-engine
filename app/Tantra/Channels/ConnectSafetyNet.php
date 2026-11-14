<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use App\Support\Social\PendingConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * tantra: the last line on the connect routes. The controllers catch
 * Exception; anything else that escapes a sign in (a TypeError from a
 * provider's odd answer, a misconfigured driver) would be a bare 500 page.
 * Here it ends on the confirmation page like any other failure, named where
 * we can. The handler still reports it, so it is never hidden.
 */
final class ConnectSafetyNet
{
    public static function render(Throwable $e, Request $request): ?RedirectResponse
    {
        if ($e instanceof HttpExceptionInterface
            || $e instanceof ValidationException
            || $e instanceof AuthorizationException
            || $e instanceof AuthenticationException
            || $e instanceof TokenMismatchException) {
            return null;
        }

        $name = (string) $request->route()?->getName();
        if (! $request->isMethod('GET') || ! preg_match('/^app\.social\.([a-z-]+)\.(connect|callback)$/', $name, $m)) {
            return null;
        }

        $platform = PreConnect::networkFromRoute("app.social.{$m[1]}.connect");
        if ($platform === null) {
            return null;
        }

        $pending = PendingConnection::current();
        if ($pending?->platform() === $platform) {
            $pending->fail(ConnectFailures::fromException($e, $platform));
        }

        return redirect()->route('app.social.connect.show', $platform);
    }
}
