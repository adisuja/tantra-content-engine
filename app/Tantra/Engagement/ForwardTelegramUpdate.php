<?php

declare(strict_types=1);

namespace App\Tantra\Engagement;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * tantra: hands a Telegram update from a private chat with the Engine's bot (someone who started it, or sent a
 * keyword) to the Tantra CRM's Engagement, unchanged, once per mapped CRM workspace that has a Telegram channel.
 * Each copy is signed for its workspace: X-Tantra-Signature = HMAC-SHA256 of the CRM workspace id, keyed with
 * TELEGRAM_ENGAGEMENT_SECRET, so one workspace's signature cannot carry updates into another. The CRM takes no bot
 * webhook directly.
 */
final class ForwardTelegramUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $crmWorkspace, public readonly string $body) {}

    public static function signature(string $crmWorkspace): string
    {
        return hash_hmac('sha256', $crmWorkspace, (string) config('tantra.engagement_channels.telegram_secret'));
    }

    /**
     * Forward when the update is a private chat message that is not the connect flow.
     *
     * @param  array<string, mixed>  $update
     */
    public static function maybe(array $update, string $body): void
    {
        if (blank(config('tantra.engagement_channels.telegram_secret'))) {
            return;
        }
        $message = data_get($update, 'message');
        if (! is_array($message) || data_get($message, 'chat.type') !== 'private') {
            return;
        }
        if (preg_match('/^\/connect(?:@\S+)?(\s|$)/', (string) data_get($message, 'text', ''))) {
            return;
        }

        foreach (EngagementChannels::workspaceMap() as $crm => $engineIds) {
            $hasTelegram = SocialAccount::query()->whereIn('workspace_id', $engineIds)
                ->where('platform', Platform::Telegram->value)->where('status', Status::Connected->value)->exists();
            if ($hasTelegram) {
                self::dispatch($crm, $body);
            }
        }
    }

    public function handle(): void
    {
        $url = rtrim((string) config('tantra.engagement_channels.telegram_forward_url'), '/').'/'.rawurlencode($this->crmWorkspace);
        $response = Http::timeout(15)
            ->withHeaders(['X-Tantra-Signature' => self::signature($this->crmWorkspace), 'X-Tantra-Workspace' => $this->crmWorkspace])
            ->withBody($this->body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException("The CRM did not take the Telegram update (HTTP {$response->status()}).");
        }
    }
}
