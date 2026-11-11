<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function tantraCreateOwner(array $parameters = []): array
{
    $exit = Artisan::call('tantra:create-owner', [
        'email' => 'Adi@Example.com',
        'name' => 'Adithya Murali',
        ...$parameters,
    ]);

    preg_match('/Password\s*\.*\s*([A-Za-z0-9]{24})/', Artisan::output(), $match);

    return [$exit, $match[1] ?? null];
}

it('creates the owner, the Adi and Tantra workspaces, and a working one-time password', function () {
    [$exit, $password] = tantraCreateOwner();

    $user = User::query()->where('email', 'adi@example.com')->firstOrFail();

    expect($exit)->toBe(0)
        ->and($password)->not->toBeNull()
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->account->owner_id)->toBe($user->id)
        ->and($user->timezone)->toBe('Asia/Kolkata')
        ->and($user->workspaces()->pluck('name')->sort()->values()->all())->toBe(['Adi', 'Tantra'])
        ->and($user->refresh()->currentWorkspace->name)->toBe('Adi');

    expect(DB::table('user_workspace')->where('user_id', $user->id)->pluck('is_admin')->map(fn ($value): bool => (bool) $value)->all())
        ->toBe([true, true])
        ->and($user->workspaces()->pluck('account_id')->unique()->all())->toBe([$user->account_id]);
});

it('takes custom workspace names', function () {
    tantraCreateOwner(['--workspace' => ['Brand', 'Personal']]);

    expect(User::query()->firstOrFail()->workspaces()->pluck('name')->sort()->values()->all())->toBe(['Brand', 'Personal']);
});

it('refuses an email that already exists and changes nothing', function () {
    tantraCreateOwner();
    [$exit] = tantraCreateOwner();

    expect($exit)->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

it('refuses an invalid email', function () {
    [$exit] = tantraCreateOwner(['email' => 'not-an-email']);

    expect($exit)->toBe(1)
        ->and(User::query()->count())->toBe(0);
});
