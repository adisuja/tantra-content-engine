<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: whether each developer app is still in development (only invited
// testers can connect) or live (anyone can). Null keeps the catalog default
// (ChannelApps::catalog() 'mode'). Not secret, so a plain column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_channel_apps', function (Blueprint $table) {
            $table->string('mode', 20)->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('tantra_channel_apps', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
