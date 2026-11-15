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
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * tantra: hands a Telegram update from a private chat with the Engine's bot (someone who started it, or sent a
 * keyword) to the Tantra CRM's Engagement, unchanged (ENGAGEMENT_CHANNELS_CONTRACT.md section 2). The bot is shared,
 * and nothing in a private chat says which team it is for, so the update goes on only while exactly one mapped CRM
 * workspace has a Telegram channel; with more than one it is held back (and logged) rather than copied to every
 * team. Contract v2 (S-06): each CRM workspace has its own secret, made and stored encrypted in Tantra and set here
 * in TELEGRAM_ENGAGEMENT_SECRETS. Every copy is signed X-Tantra-Signature: t=<unix>,kid=<id>,v1=<hex HMAC-SHA256 of
 * "<t>.<workspace>.<body>">, so a secret reaches only its own workspace, a copy cannot be changed or replayed after
 * 5 minutes, and Tantra can rotate or revoke one workspace's secret alone. No secret for the workspace: nothing
 * is sent. The CRM takes no bot webhook directly.
 */
final class ForwardTelegramUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $crmWorkspace, public readonly string $body) {}

    /**
     * CRM workspace id => [kid, secret], from "ws_abc=hk_1:secret,ws_def=hk_2:secret". Malformed entries are dropped.
     *
     * @return array<string, array{kid: string, secret: string}>
     */
    public static function secrets(): array
    {
        $out = [];
        foreach (explode(',', (string) config('tantra.engagement_channels.telegram_secrets', '')) as $entry) {
            [$ws, $rest] = array_pad(explode('=', trim($entry), 2), 2, '');
            [$kid, $secret] = array_pad(explode(':', $rest, 2), 2, '');
            if (preg_match('/^ws_[\w-]{3,64}$/', $ws) === 1 && preg_match('/^hk_[a-z0-9]{8,40}$/', $kid) === 1 && strlen($secret) >= 32) {
                $out[$ws] = ['kid' => $kid, 'secret' => $secret];
            }
        }

        return $out;
    }

    /** The X-Tantra-Signature header for one copy, or null when the workspace has no secret. */
    public static function signature(string $crmWorkspace, string $body, ?int $time = null): ?string
    {
        $key = self::secrets()[$crmWorkspace] ?? null;
        if ($key === null) {
            return null;
        }
        $t = $time ?? time();

        return "t={$t},kid={$key['kid']},v1=".hash_hmac('sha256', "{$t}.{$crmWorkspace}.{$body}", $key['secret']);
    }

    /**
     * Forward when the update is a private chat message that is not the connect flow.
     *
     * @param  array<string, mixed>  $update
     */
    public static function maybe(array $update, string $body): void
    {
        if (self::secrets() === []) {
            return;
        }
        $message = data_get($update, 'message');
        if (! is_array($message) || data_get($message, 'chat.type') !== 'private') {
            return;
        }
        if (preg_match('/^\/connect(?:@\S+)?(\s|$)/', (string) data_get($message, 'text', ''))) {
            return;
        }

        if (blank(config('tantra.engagement_channels.telegram_forward_url'))) {
            return;
        }

        $targets = [];
        foreach (EngagementChannels::workspaceMap() as $crm => $engineIds) {
            $hasTelegram = SocialAccount::query()->whereIn('workspace_id', $engineIds)
                ->where('platform', Platform::Telegram->value)->where('status', Status::Connected->value)->exists();
            if ($hasTelegram) {
                $targets[] = $crm;
            }
        }

        // One shared bot: a private chat names no team, so never copy someone's DM to every team.
        if (count($targets) > 1) {
            Log::warning('Engagement channels: Telegram update held back, more than one workspace has a Telegram channel', ['update_id' => data_get($update, 'update_id'), 'workspaces' => count($targets)]);

            return;
        }
        if ($targets !== [] && isset(self::secrets()[$targets[0]])) {
            self::dispatch($targets[0], $body);
        }
    }

    public function handle(): void
    {
        // Signed when sent, not when queued, so a retry carries a fresh time.
        $signature = self::signature($this->crmWorkspace, $this->body);
        if ($signature === null) {
            return;
        }
        $url = rtrim((string) config('tantra.engagement_channels.telegram_forward_url'), '/').'/'.rawurlencode($this->crmWorkspace);
        $response = Http::timeout(15)
            ->withHeaders(['X-Tantra-Signature' => $signature, 'X-Tantra-Workspace' => $this->crmWorkspace])
            ->withBody($this->body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException("The CRM did not take the Telegram update (HTTP {$response->status()}).");
        }
    }
}
