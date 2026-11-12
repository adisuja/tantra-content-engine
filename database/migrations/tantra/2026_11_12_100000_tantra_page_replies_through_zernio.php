<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: on Tantra page posts the page itself answers through Zernio; teammates
// keep posting through their own Unipile accounts (Spike S6, 2026-11-12).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_boost_actions', function (Blueprint $table) {
            $table->string('via', 16)->default('unipile')->after('unipile_account_id');
        });
        Schema::table('tantra_giveaway_replies', function (Blueprint $table) {
            $table->string('via', 16)->default('unipile')->after('review_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('tantra_boost_actions', fn (Blueprint $table) => $table->dropColumn('via'));
        Schema::table('tantra_giveaway_replies', fn (Blueprint $table) => $table->dropColumn('via'));
    }
};
