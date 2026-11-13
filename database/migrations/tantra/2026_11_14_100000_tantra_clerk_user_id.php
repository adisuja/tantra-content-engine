<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: the Clerk user (usetantra.com login) behind each Engine user (2026-11-14).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('clerk_user_id')->nullable()->unique();
            // When this user was given team access (TeamAccess); the command adopts no other row without --force.
            $table->timestamp('tantra_team_access_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['clerk_user_id']);
            $table->dropColumn(['clerk_user_id', 'tantra_team_access_at']);
        });
    }
};
