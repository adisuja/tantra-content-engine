<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Channels\ChannelApps;
use App\Tantra\Http\Controllers\Web\ChannelAppController;
use App\Tantra\Ops\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * tantra: One Content app PRD 4.1, the CRM's Engine card over the signed gateway.
 *
 *   GET  /api/gateway/tantra/ops                             health (queues, scheduler, Zernio, Unipile), queue depth, failed jobs in
 *                                                            24 hours. The instance owner also gets the latest failed jobs and the
 *                                                            developer apps, read only (no secret values ever).
 *   POST /api/gateway/tantra/ops/failed-jobs/{uuid}/retry    put one failed job back on its queue (instance owner only, 202)
 *
 * Failed jobs and developer apps are instance wide, so only the instance owner (ChannelApps::canManage) sees or retries them.
 */
class GatewayOpsController extends Controller
{
    public const int FAILED_LIST = 20;

    public function summary(Request $request): JsonResponse
    {
        $owner = ChannelApps::canManage($request->user());
        $health = Health::report();

        return response()->json(['data' => [
            'ok' => $health['ok'],
            'problems' => $health['problems'],
            'checked_at' => $health['checked_at'],
            'queues' => $health['queues'],
            'queue_depth' => array_sum(array_filter($health['queues'], 'is_int')),
            'scheduler_tick' => $health['scheduler_tick'],
            'failed_jobs_24h' => $health['failed_jobs_24h'],
            'zernio' => $health['zernio'],
            'unipile' => $health['unipile'],
            'instance_owner' => $owner,
            'failed_jobs' => $owner ? $this->failedJobs() : null,
            'developer_apps' => $owner ? ChannelAppController::present(false) : null,
        ]]);
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        abort_unless(ChannelApps::canManage($request->user()), Response::HTTP_FORBIDDEN, 'Only the instance owner can retry engine jobs.');
        abort_unless(Str::isUuid($uuid) && DB::table('failed_jobs')->where('uuid', $uuid)->exists(), Response::HTTP_NOT_FOUND);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Back on its queue.', 'data' => ['uuid' => $uuid]], Response::HTTP_ACCEPTED);
    }

    /** @return list<array<string, mixed>> */
    private function failedJobs(): array
    {
        try {
            return DB::table('failed_jobs')->orderByDesc('failed_at')->limit(self::FAILED_LIST)
                ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(fn (object $row): array => [
                    'uuid' => $row->uuid,
                    'queue' => $row->queue,
                    'job' => (string) (json_decode((string) $row->payload, true)['displayName'] ?? 'job'),
                    'error' => mb_substr((string) strtok((string) $row->exception, "\n"), 0, 300),
                    'failed_at' => $row->failed_at,
                ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
