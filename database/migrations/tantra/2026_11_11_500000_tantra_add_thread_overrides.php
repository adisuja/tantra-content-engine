<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: the review owner's edits to a post's team thread, kept apart from the
// engine's own text (PRD 10.5 step 2): {index: {text?, excluded?}}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_review_items', function (Blueprint $table) {
            $table->json('thread_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tantra_review_items', function (Blueprint $table) {
            $table->dropColumn('thread_overrides');
        });
    }
};
