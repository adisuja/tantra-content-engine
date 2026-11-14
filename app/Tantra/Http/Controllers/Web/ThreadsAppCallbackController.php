<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Tantra\Social\DeleteThreadsUserData;
use App\Tantra\Social\MetaSignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * tantra: the Uninstall and Delete callbacks of the Meta "Threads API" use case.
 * Meta posts a `signed_request` naming the Threads user when they remove the
 * app (uninstall) or ask for their data to be deleted (delete). Both stop the
 * Engine acting for that user at once (channel disconnected, token dropped);
 * delete then removes the channel, its posts and its analytics in a queued job
 * that retries until done, and answers Meta with a status URL and a code.
 */
final class ThreadsAppCallbackController extends Controller
{
    public function uninstall(Request $request): JsonResponse
    {
        $userId = $this->threadsUserId($request);

        if ($userId === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $this->disconnect($userId);
        Log::info('Threads app removed by a user', ['threads_user_id' => $userId]);

        return response()->json(['success' => true]);
    }

    public function delete(Request $request): JsonResponse
    {
        $userId = $this->threadsUserId($request);

        if ($userId === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $this->disconnect($userId);

        $code = strtoupper(Str::random(12));
        DeleteThreadsUserData::dispatch($userId, $code);
        Log::info('Threads data deletion requested', ['threads_user_id' => $userId, 'code' => $code]);

        return response()->json([
            'url' => URL::signedRoute('tantra.threads.deletion', ['code' => $code, 'at' => now()->timestamp]),
            'confirmation_code' => $code,
        ]);
    }

    public function status(Request $request, string $code): View
    {
        return view('tantra::threads-deletion', [
            'code' => $code,
            'at' => now()->setTimestamp((int) $request->query('at'))->toFormattedDayDateString(),
        ]);
    }

    /** Nothing may act for this Threads user again, whatever happens to the rest. */
    private function disconnect(string $userId): void
    {
        $accounts = SocialAccount::query()
            ->where('platform', Platform::Threads)
            ->where('platform_user_id', $userId)
            ->with('workspace.owner')
            ->get();

        foreach ($accounts as $account) {
            $account->markAsDisconnected(__('tantra.threads_app.removed_in_threads'));
            $account->forceFill(['access_token' => '', 'refresh_token' => null])->save();
        }
    }

    /** Meta signs with the Threads app secret; the parent Meta app secret is accepted too. */
    private function threadsUserId(Request $request): ?string
    {
        $payload = MetaSignedRequest::parse($request->input('signed_request'), [
            config('services.threads.client_secret'),
            config('services.facebook.client_secret'),
        ]);
        $userId = $payload['user_id'] ?? null;

        return (is_string($userId) || is_int($userId)) && (string) $userId !== '' ? (string) $userId : null;
    }
}
