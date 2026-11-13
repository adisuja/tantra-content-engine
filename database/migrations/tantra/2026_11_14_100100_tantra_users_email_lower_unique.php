<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// tantra: one user per email whatever its case, since Clerk sign in matches on
// the email (2026-11-14). Fails, listing them, while emails differ only by case.
return new class extends Migration
{
    private const INDEX = 'users_email_lower_unique';

    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('lower(email) as email, count(*) as total')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('email');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('tantra: these emails belong to more than one user, differing only by case. Merge or rename them, then migrate again: '.$duplicates->implode(', '));
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('create unique index '.self::INDEX.' on users (lower(email))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true) && Schema::hasTable('users')) {
            DB::statement('drop index if exists '.self::INDEX);
        }
    }
};
