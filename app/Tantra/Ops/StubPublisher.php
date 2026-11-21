<?php

declare(strict_types=1);

namespace App\Tantra\Ops;

use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Tantra\Unipile\UnipilePublishException;
use Illuminate\Support\Str;

/**
 * tantra: Speed P3 capacity test only. Stands in for every network publisher when TANTRA_PUBLISH_STUB is on: it waits latency_ms (a
 * network round trip) and answers a fake post id, so a publish burst exercises the queue, the workers, the database and the CRM
 * notifications without anything reaching a network. Never on in production, whatever the switch says.
 */
final class StubPublisher
{
    public static function enabled(): bool
    {
        return (bool) config('tantra.publish_stub.enabled') && ! app()->environment('production');
    }

    /** @return array{id: string, url: null} */
    public function publish(Post $post): array
    {
        $ms = max(0, (int) config('tantra.publish_stub.latency_ms', 800));
        if ($ms > 0) {
            usleep($ms * 1000);
        }
        $failPercent = max(0, min(100, (int) config('tantra.publish_stub.fail_percent', 0)));
        if ($failPercent > 0 && random_int(1, 100) <= $failPercent) {
            throw new UnipilePublishException('Stub publisher: a planned failure for the load test.', ErrorCategory::Unknown);
        }

        return ['id' => 'stub_'.Str::lower((string) Str::ulid()), 'url' => null];
    }
}
