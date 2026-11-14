<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: a LinkedIn profile rebrand, written by an agent job and approved by the person only (PRD 25.11, review M12).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_rebrands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('agent_job_id')->nullable();
            $table->string('status', 16); // writing, proposed, applied, dismissed
            $table->json('proposal')->nullable();
            $table->json('applied')->nullable();
            $table->string('banner_path', 300)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_rebrands');
    }
};
