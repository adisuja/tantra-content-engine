<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: one row per AI call (A-05b). workspace_ai_usages was dropped on 30 Sep, so the engine recorded no AI usage at
// all. Each row is sent to Tantra's AI cost ledger (POST /api/ai/usage) by tantra:report-ai-usage and kept 90 days.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_ai_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_id', 64)->nullable()->index();
            $table->string('task', 40);
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->string('outcome', 10)->default('ok');
            $table->string('error', 200)->nullable();
            $table->timestamp('reported_at')->nullable()->index();
            $table->string('report_error', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_ai_usages');
    }
};
