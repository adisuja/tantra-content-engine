<?php

declare(strict_types=1);

namespace App\Tantra\Roadmap;

/** tantra: the roadmap's columns, left to right. "q" holds new requests. */
enum Column: string
{
    case Q = 'q';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Beta = 'beta';
    case Shipped = 'shipped';

    public const DEFAULT = self::Q;

    public function label(): string
    {
        return match ($this) {
            self::Q => 'Q: Requests',
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Beta => 'Beta',
            self::Shipped => 'Shipped',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Q => 'Ideas from you. We read every one.',
            self::Planned => 'We are doing these next.',
            self::InProgress => 'Being built right now.',
            self::Beta => 'Live, still being polished.',
            self::Shipped => 'Done and in your hands.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $column): string => $column->value, self::cases());
    }
}
