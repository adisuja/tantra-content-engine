<?php

declare(strict_types=1);

namespace App\Tantra\Notify\Mirror;

use Illuminate\Support\Facades\DB;

/**
 * tantra: Speed P3 (B3). A monotonically increasing version per thing the CRM mirrors. The next version is the larger of the thing's own
 * updated_at in ms (so it compares with the versions the CRM's reconcile writes, which are updated_at in ms) and the last one sent plus one.
 * One atomic upsert: two workers sending for the same post at once never get the same number.
 */
final class Versions
{
    public static function next(string $key, int $floorMs): int
    {
        $floor = max(1, $floorMs);
        $args = [mb_substr($key, 0, 120), $floor, now()];
        if (DB::getDriverName() === 'mysql') {
            // MySQL has no RETURNING: the upsert locks the row until commit, so the read in the same transaction is still atomic.
            return (int) DB::transaction(function () use ($args) {
                DB::statement(
                    'INSERT INTO tantra_mirror_versions (`key`, version, updated_at) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE version = GREATEST(version + 1, VALUES(version)), updated_at = VALUES(updated_at)',
                    $args,
                );

                return DB::selectOne('SELECT version FROM tantra_mirror_versions WHERE `key` = ?', [$args[0]])->version;
            });
        }
        $row = DB::selectOne(
            'INSERT INTO tantra_mirror_versions (key, version, updated_at) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET version = CASE WHEN tantra_mirror_versions.version + 1 > excluded.version
                 THEN tantra_mirror_versions.version + 1 ELSE excluded.version END, updated_at = excluded.updated_at
             RETURNING version',
            $args,
        );

        return (int) $row->version;
    }
}
