<?php

declare(strict_types=1);

namespace App\Tantra\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tantra: the shared key the Tantra app uses server to server (ENGINE_CHANNELS_KEY, the same one as the engagement
 * channels endpoint). Compared in constant time; a wrong, missing or unset key answers 401 and nothing else is said.
 */
class EnsureCrmKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('tantra.engagement_channels.key');

        if ($key === '' || ! hash_equals($key, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Invalid or missing key', 'code' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
