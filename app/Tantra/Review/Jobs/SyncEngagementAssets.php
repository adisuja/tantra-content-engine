<?php

declare(strict_types=1);

namespace App\Tantra\Review\Jobs;

use App\Models\User;
use App\Tantra\Auth\TeamAccess;
use App\Tantra\Links\CrmTracking;
use App\Tantra\Review\EngagementAsset;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * tantra: sends imported posts' giveaways (DM, connection note, email and resource link) to the Tantra CRM's
 * Engagement asset library (POST /api/engagement/assets/import, server key TANTRA_CRM_API_KEY), so a watched post
 * starts from the Studio copy as the review owner left it. The CRM upserts by (studio, post id), the same records a
 * posts.json import makes, so a re-push or an edit updates them. Only the main Account (the team's CRM workspace),
 * and only with TANTRA_CRM_ENGAGEMENT_ASSETS on (the default) and a key set.
 */
final class SyncEngagementAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param list<string> $itemIds */
    public function __construct(public readonly array $itemIds) {}

    public static function enabledFor(?string $accountId): bool
    {
        return (bool) config('tantra.crm.engagement_assets', true)
            && CrmTracking::configured()
            && $accountId !== null
            && $accountId === TeamAccess::mainAccount()?->id;
    }

    /** @param list<string|null> $itemIds */
    public static function afterImport(User $owner, array $itemIds): void
    {
        $ids = array_values(array_filter($itemIds, fn (mixed $id): bool => is_string($id) && $id !== ''));
        if ($ids !== [] && self::enabledFor($owner->account_id)) {
            self::dispatch($ids);
        }
    }

    public static function forItem(ReviewItem $item): void
    {
        if (self::enabledFor($item->account_id)) {
            self::dispatch([$item->id]);
        }
    }

    public function handle(): void
    {
        $records = ReviewItem::query()->whereIn('id', $this->itemIds)->get()
            ->map(fn (ReviewItem $item): ?array => EngagementAsset::record($item))
            ->filter()
            ->values()
            ->all();
        if ($records === []) {
            return;
        }

        $response = Http::baseUrl(rtrim((string) config('tantra.crm.url'), '/'))
            ->withToken((string) config('tantra.crm.api_key'))
            ->acceptJson()
            ->timeout(20)
            ->post('/api/engagement/assets/import', ['posts' => $records]);

        if (! $response->successful()) {
            throw new RuntimeException("The CRM did not take the Engagement assets (HTTP {$response->status()}).");
        }
    }
}
