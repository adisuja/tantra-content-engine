<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: a personal poll posted by hand holds its LinkedIn slot like a post
// does (PRD section 9, "tantra_manual_tasks"). X tasks hold no slot.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_manual_tasks', function (Blueprint $table) {
            $table->foreignUuid('social_account_id')->nullable()->after('workspace_id')->constrained('social_accounts')->nullOnDelete();
            $table->boolean('slot_fallback')->default(false)->after('due_at');
            $table->index(['social_account_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tantra_manual_tasks', function (Blueprint $table) {
            $table->dropIndex(['social_account_id', 'status', 'due_at']);
            $table->dropConstrainedForeignId('social_account_id');
            $table->dropColumn('slot_fallback');
        });
    }
};
