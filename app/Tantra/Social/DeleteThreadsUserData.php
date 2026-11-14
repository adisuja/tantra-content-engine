<?php

declare(strict_types=1);

namespace App\Tantra\Social;

use App\Actions\Post\DeleteChannelPosts;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * tantra: a Threads user asked Meta to delete their data. Removes every Engine
 * channel for that Threads account, its posts, and the analytics kept for it
 * (the normal disconnect keeps analytics; a deletion request must not). A busy
 * channel queue retries later, so the request always completes.
 */
final class DeleteThreadsUserData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 12;

    /** @var list<int> */
    public array $backoff = [30, 60, 300, 900, 1800, 3600];

    public function __construct(public string $threadsUserId, public string $code) {}

    public function handle(): void
    {
        $accounts = SocialAccount::query()
            ->where('platform', Platform::Threads)
            ->where('platform_user_id', $this->threadsUserId)
            ->get();

        foreach ($accounts as $account) {
            ReflowChannelQueue::withLock([$account->id], fn () => DB::transaction(function () use ($account): void {
                DeleteChannelPosts::forAccount($account);
                $account->delete();
            }));
        }

        $names = array_values(array_unique([Platform::Threads->value, Platform::Threads->network()]));

        foreach (['analytics_publications', 'analytics_account_daily_snapshots'] as $table) {
            DB::table($table)->whereIn('platform', $names)->where('platform_user_id', $this->threadsUserId)->delete();
        }

        DB::table('analytics_sync_states')->whereIn('network', $names)->where('platform_user_id', $this->threadsUserId)->delete();

        Log::info('Threads data deletion completed', ['threads_user_id' => $this->threadsUserId, 'channels' => $accounts->count(), 'code' => $this->code]);
    }
}
