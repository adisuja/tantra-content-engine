<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\User;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Unipile\UnipileClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * tantra: match each teammate to their LinkedIn account in Tantra's Unipile
 * (PRD 10.5 step 3). Lists the accounts with a suggested match by name; a match
 * is used only once it is confirmed here. Consent stays each teammate's own.
 */
class TeamAccountsCommand extends Command
{
    protected $signature = 'tantra:team-accounts
        {--link=* : email=unipile_account_id, confirms that match}
        {--unlink=* : email, removes the match}';

    protected $description = 'List and confirm which Unipile LinkedIn account belongs to which teammate';

    public function handle(UnipileClient $unipile): int
    {
        if (! UnipileClient::configured()) {
            $this->components->error('UNIPILE_DSN and UNIPILE_API_KEY are not set on the server.');

            return self::FAILURE;
        }

        $owner = User::query()->whereHas('account', fn ($q) => $q->whereColumn('accounts.owner_id', 'users.id'))->first();
        $accounts = collect($unipile->accounts())->filter(fn ($a): bool => is_array($a) && strtolower((string) ($a['type'] ?? $a['provider'] ?? 'linkedin')) === 'linkedin')->values();

        foreach ((array) $this->option('link') as $pair) {
            [$email, $id] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $user = User::query()->where('email', strtolower(trim($email)))->first();
            $account = $accounts->first(fn (array $a): bool => ($a['id'] ?? null) === trim($id));
            if ($user === null || $account === null) {
                $this->components->error("Skipped {$pair}: unknown email or Unipile account.");

                continue;
            }
            MemberProfile::query()->updateOrCreate(['user_id' => $user->id], [
                'unipile_account_id' => $account['id'],
                'unipile_name' => $account['name'] ?? null,
                'unipile_match_method' => 'confirmed',
                'unipile_confirmed_by' => $owner?->id,
                'unipile_confirmed_at' => now(),
            ]);
            $this->components->info("{$user->email} is {$account['name']} ({$account['id']}).");
        }

        foreach ((array) $this->option('unlink') as $email) {
            $user = User::query()->where('email', strtolower(trim((string) $email)))->first();
            if ($user !== null) {
                MemberProfile::query()->whereKey($user->id)->update(['unipile_account_id' => null, 'unipile_confirmed_at' => null]);
                $this->components->info("Unlinked {$user->email}.");
            }
        }

        $profiles = MemberProfile::query()->get()->keyBy('unipile_account_id');
        $users = User::query()->orderBy('name')->get();
        $this->table(['Unipile account', 'Name on LinkedIn', 'Status', 'Engine user'], $accounts->map(function (array $a) use ($profiles, $users): array {
            $linked = $profiles->get($a['id'] ?? '');
            $suggested = $users->first(fn (User $u): bool => Str::lower($u->name) === Str::lower((string) ($a['name'] ?? '')));

            return [
                $a['id'] ?? '?',
                $a['name'] ?? '',
                strtolower((string) ($a['status'] ?? data_get($a, 'sources.0.status') ?? '')),
                $linked ? $linked->user?->email.' (confirmed'.($linked->boost_consent_at ? ', consent given' : ', no consent yet').')' : ($suggested ? 'suggest: '.$suggested->email : ''),
            ];
        })->all());

        return self::SUCCESS;
    }
}
