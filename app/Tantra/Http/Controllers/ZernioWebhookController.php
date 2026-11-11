<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * tantra: Zernio's post.published / post.failed / post.partial events (PRD 10.4
 * step 6). Verified with X-Zernio-Signature (HMAC-SHA256 of the raw body). The
 * event is recorded on the Engine post; the publish job, which reads the same
 * Zernio post on its next attempt, settles the publication.
 */
class ZernioWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('tantra.zernio.webhook_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Zernio-Signature'))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = (string) $request->input('event');
        $zernioPostId = (string) $request->input('post.id');

        if (! in_array($event, ['post.published', 'post.failed', 'post.partial'], true) || $zernioPostId === '') {
            return response()->json(['ok' => true]);
        }

        $post = Post::query()->where('meta->zernio_post_id', $zernioPostId)->first();
        if ($post === null) {
            return response()->json(['ok' => true]);
        }

        $target = collect((array) $request->input('post.platforms'))->first() ?? [];
        $post->forceFill(['meta' => [...((array) $post->meta), 'zernio_event' => array_filter([
            'event' => $event,
            'event_id' => (string) $request->header('X-Zernio-Event-Id'),
            'url' => $target['publishedUrl'] ?? null,
            'error' => $target['error'] ?? null,
            'at' => now()->toIso8601String(),
        ])]])->saveQuietly();

        Log::info('Zernio webhook', ['post_id' => $post->id, 'event' => $event]);

        return response()->json(['ok' => true]);
    }
}
