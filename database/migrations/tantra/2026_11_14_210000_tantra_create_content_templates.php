<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: content templates, one per sample, and new ones built from a pasted post (PRD 25.9).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_content_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->nullable()->constrained()->cascadeOnDelete(); // null: a seeded sample, everyone sees it
            $table->string('key', 32)->unique();
            $table->string('title', 200);
            $table->string('platform', 32)->default('linkedin');
            $table->string('format', 80)->nullable();
            $table->string('family', 40)->nullable();
            $table->string('archetype', 300)->nullable();
            $table->string('creator', 120)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->text('description')->nullable();
            $table->longText('spec')->nullable();
            $table->text('copy_template')->nullable();
            $table->json('palette')->nullable();
            $table->string('preview_path', 300)->nullable();
            $table->longText('sample_md')->nullable();
            $table->string('status', 16)->default('live'); // building, live, failed, archived
            $table->string('origin', 16)->default('seed'); // seed, intake
            $table->string('note', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('agent_job_id')->nullable();
            $table->timestamps();
            $table->index(['platform', 'format']);
            $table->index(['origin', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_content_templates');
    }
};
