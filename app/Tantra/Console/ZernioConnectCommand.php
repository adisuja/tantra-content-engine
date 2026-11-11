<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Zernio\ZernioClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * tantra: link a LinkedIn page connected in Zernio as a channel of a workspace
 * (PRD 10.4). Zernio holds the LinkedIn grant; the Engine stores only the Zernio
 * account id. At most two Zernio channels: a third costs money.
 */
class ZernioConnectCommand extends Command
{
    protected $signature = 'tantra:zernio-connect
        {workspace : Workspace name or id}
        {account? : Zernio account id (omit to list the LinkedIn accounts)}
        {--times=10:30 : Posting times, Monday to Friday, comma separated}
        {--timezone=America/New_York : The channel time zone}';

    protected $description = 'Link a Zernio LinkedIn page to a workspace as its channel';

    public function handle(ZernioClient $zernio): int
    {
        if (! ZernioClient::configured()) {
            $this->components->error('ZERNIO_API_KEY is not set on the server.');

            return self::FAILURE;
        }

        $ref = (string) $this->argument('workspace');
        $workspace = (Str::isUuid($ref) ? Workspace::query()->whereKey($ref)->first() : null)
            ?? Workspace::query()->where('name', $ref)->first();
        if ($workspace === null) {
            $this->components->error("No workspace named or with id {$ref}.");

            return self::FAILURE;
        }

        $accounts = collect($zernio->accounts())->filter(fn ($a): bool => is_array($a) && ($a['platform'] ?? null) === 'linkedin')->values();
        $linked = SocialAccount::query()->where('platform', Platform::ZernioLinkedInPage)->pluck('platform_user_id')->all();
        $id = (string) $this->argument('account');

        if ($id === '') {
            foreach ($accounts as $a) {
                $aid = (string) ($a['_id'] ?? $a['id'] ?? '');
                $this->line(sprintf('%s  %s  %s%s', $aid, $a['displayName'] ?? $a['name'] ?? '', $a['username'] ?? '', in_array($aid, $linked, true) ? '  (linked)' : ''));
            }

            return self::SUCCESS;
        }

        $account = $accounts->first(fn (array $a): bool => ($a['_id'] ?? $a['id'] ?? null) === $id);
        if ($account === null) {
            $this->components->error("No LinkedIn account {$id} in Zernio.");

            return self::FAILURE;
        }
        if (! in_array($id, $linked, true) && count($linked) >= (int) config('tantra.zernio.max_accounts', 2)) {
            $this->components->error('Two Zernio channels are linked already. A third costs $6 a month, so it is not offered.');

            return self::FAILURE;
        }

        $schedule = PostingSchedule::empty();
        foreach ([1, 2, 3, 4, 5] as $day) {
            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('times')))) as $time) {
                $schedule = $schedule->withTime($day, $time);
            }
        }

        $channel = SocialAccount::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => $id],
            [
                'username' => (string) ($account['username'] ?? ''),
                'display_name' => (string) ($account['displayName'] ?? $account['name'] ?? $account['username'] ?? 'LinkedIn page'),
                'avatar_url' => $account['profilePicture'] ?? $account['avatar'] ?? null,
                'access_token' => 'zernio-managed',
                'status' => Status::Connected,
                'timezone' => (string) $this->option('timezone'),
                'posting_schedule' => $schedule,
                'meta' => array_filter([
                    'provider' => 'zernio',
                    'profile_url' => $account['profileUrl'] ?? null,
                    'organization_urn' => data_get($account, 'metadata.organizationUrn') ?? data_get($account, 'organizationUrn'),
                ]),
            ],
        );

        $this->components->info("{$channel->display_name} is now the Zernio channel of {$workspace->name}.");

        return self::SUCCESS;
    }
}
