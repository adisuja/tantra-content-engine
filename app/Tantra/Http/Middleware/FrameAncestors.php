<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use App\Tantra\Embed\Embed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: only the Engine itself and the Tantra CRM (TANTRA_EMBED_PARENTS)
 * may frame its pages (PRD 2026-11-15, 4.7). First in the web group, so a
 * response cut short by a later middleware carries it too. A response that
 * already has a Content-Security-Policy (media files, favicons) keeps it.
 */
class FrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::value());
        }

        return $response;
    }

    public static function value(): string
    {
        return trim("frame-ancestors 'self' ".implode(' ', Embed::parents()));
    }
}
