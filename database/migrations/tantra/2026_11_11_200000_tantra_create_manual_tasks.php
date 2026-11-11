<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: posts the owner publishes by hand: X posts and threads, and polls on
// personal LinkedIn profiles (PRD section 9, "tantra_manual_tasks", and 12).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_manual_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_item_id')->unique()->constrained('tantra_review_items')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->string('status')->default('waiting');
            $table->timestamp('due_at')->nullable();
            $table->text('text');
            $table->json('thread')->nullable();
            $table->json('poll')->nullable();
            $table->text('media_url')->nullable();
            $table->text('posted_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_manual_tasks');
    }
};
