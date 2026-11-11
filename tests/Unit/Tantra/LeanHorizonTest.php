<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Tantra\Ops\LeanHorizon;
use App\Tantra\TantraServiceProvider;
use Laravel\Horizon\ProvisioningPlan;

function tantraLeanPlan(int $processes = 3): array
{
    return LeanHorizon::plan(config('horizon.defaults'), Platform::enabledQueues(), $processes);
}

test('one pool works every queue upstream supervisors work', function () {
    $upstream = collect(config('horizon.defaults'))
        ->flatMap(fn (array $supervisor): array => (array) $supervisor['queue'])
        ->unique()
        ->values();

    $lean = tantraLeanPlan()[LeanHorizon::SUPERVISOR]['queue'];

    expect($upstream->diff($lean)->all())->toBe([])
        ->and($lean)->toBe(array_values(array_unique($lean)));
});

test('publishing queues come first', function () {
    $publishing = Platform::enabledQueues();
    $lean = tantraLeanPlan()[LeanHorizon::SUPERVISOR]['queue'];

    expect(array_slice($lean, 0, count($publishing)))->toBe($publishing);
});

test('the pool outlives the longest upstream job and redis never reserves a job twice', function () {
    $longest = collect(config('horizon.defaults'))->max('timeout');
    $pool = tantraLeanPlan()[LeanHorizon::SUPERVISOR];

    expect($pool['timeout'])->toBe($longest)
        ->and($pool['timeout'])->toBeLessThan(config('queue.connections.redis.retry_after'));
});

test('the pool has a fixed, small number of workers', function () {
    $pool = tantraLeanPlan(3)[LeanHorizon::SUPERVISOR];

    expect($pool['balance'])->toBeFalse()
        ->and($pool['maxProcesses'])->toBe(3)
        ->and(tantraLeanPlan(0)[LeanHorizon::SUPERVISOR]['maxProcesses'])->toBe(1);
});

test('tantra supervisors keep their own workers', function () {
    $boost = ['connection' => 'redis', 'queue' => ['tantra-boost'], 'maxProcesses' => 2, 'timeout' => 60];
    $plan = LeanHorizon::plan([...config('horizon.defaults'), 'tantra-boost' => $boost], Platform::enabledQueues(), 3);

    expect($plan['tantra-boost'])->toBe($boost)
        ->and($plan[LeanHorizon::SUPERVISOR]['queue'])->not->toContain('tantra-boost');
});

test('outside production upstream supervisors are left alone', function () {
    expect(config('horizon.defaults'))->toHaveKeys(['social-publishing', 'rss-feeds', 'media-imports'])
        ->and(config('horizon.defaults'))->not->toHaveKey(LeanHorizon::SUPERVISOR);
});

test('in production the provider swaps in the lean pool and names it for the environment', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['tantra.lean_workers' => ['enabled' => true, 'processes' => 3]]);

    (new TantraServiceProvider(app()))->boot();

    expect(array_keys(config('horizon.defaults')))->toBe([LeanHorizon::SUPERVISOR])
        ->and(config('horizon.environments.production'))->toBe([LeanHorizon::SUPERVISOR => ['maxProcesses' => 3]]);
});

test('horizon can start with the lean pool in every environment', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['tantra.lean_workers' => ['enabled' => true, 'processes' => 3]]);

    (new TantraServiceProvider(app()))->boot();

    // This is what `php artisan horizon` builds first; it read every
    // environment and crashed when one still named a removed supervisor.
    $options = ProvisioningPlan::get('tantra-test')->toSupervisorOptions();

    expect(array_keys($options))->toEqualCanonicalizing(array_keys(config('horizon.environments')))
        ->and($options)->each(fn ($environment) => $environment->toHaveKeys([LeanHorizon::SUPERVISOR]))
        ->and($options['production'][LeanHorizon::SUPERVISOR]->maxProcesses)->toBe(3)
        ->and($options['production'][LeanHorizon::SUPERVISOR]->connection)->toBe('redis');
});

test('the switch turns lean workers off in production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['tantra.lean_workers' => ['enabled' => false, 'processes' => 3]]);

    (new TantraServiceProvider(app()))->boot();

    expect(config('horizon.defaults'))->toHaveKey('social-publishing')
        ->and(config('horizon.defaults'))->not->toHaveKey(LeanHorizon::SUPERVISOR);
});
