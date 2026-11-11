<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tantra\Ops\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** tantra: GET /tantra/health with the pinger's bearer token (PRD 8.5). Off (404) while no token is set. */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('tantra.health.token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 404);

        $report = Health::report();

        return response()->json($report, $report['ok'] ? 200 : 503);
    }
}
