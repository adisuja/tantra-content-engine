<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: One Content app PRD 3.5. Joining a team is consent to boost. Turning boost off is its own fact (boost_opted_out_at), never a
// null consent, so a backfill can tell "never asked" from "said no". A member's own pause is apart from the 72 hour pause after a
// LinkedIn restriction (boost_paused_until), so Brand > Team can say which one it is.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_member_profiles', function (Blueprint $table) {
            $table->timestamp('boost_opted_out_at')->nullable()->after('boost_consent_at');
            $table->timestamp('boost_member_paused_until')->nullable()->after('boost_paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('tantra_member_profiles', function (Blueprint $table) {
            $table->dropColumn(['boost_opted_out_at', 'boost_member_paused_until']);
        });
    }
};
