<?php

declare(strict_types=1);

namespace App\Tantra\Agent;

/** tantra: which applier handles which job kind. Features register theirs in TantraServiceProvider. */
final class Appliers
{
    /** @var array<string, class-string<JobApplier>> */
    private static array $map = ['ping' => PingApplier::class];

    /** @param class-string<JobApplier> $class */
    public static function register(string $kind, string $class): void
    {
        self::$map[$kind] = $class;
    }

    public static function for(string $kind): ?JobApplier
    {
        $class = self::$map[$kind] ?? null;

        return $class ? app($class) : null;
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::$map);
    }
}
