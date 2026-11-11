<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Analytics\BatchResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** tantra: GET /api/tantra/results/{engineBatchId}, read by kits/content/engine_pull.py (PRD 10.6). */
class ResultsController extends Controller
{
    public function show(Request $request, string $engineBatchId): JsonResponse
    {
        return response()->json(BatchResults::for((string) $request->user()->account_id, $engineBatchId));
    }
}
