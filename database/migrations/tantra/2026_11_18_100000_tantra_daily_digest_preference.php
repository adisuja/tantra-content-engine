<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: the daily digest email (App\Tantra\Digest). On by default; the
// sent-on date keeps it to one a day in each person's own timezone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('daily_digest')->default(true);
            $table->date('daily_digest_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['daily_digest', 'daily_digest_sent_on']);
        });
    }
};
