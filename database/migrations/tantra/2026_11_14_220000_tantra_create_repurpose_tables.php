<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: repost and the repurposing cadence (PRD 25.10 and 25.15 M6).
return new class extends Migration
{
    public function up(): void
    {
        // The preset workflow per target network, for everyone; admins edit it.
        Schema::create('tantra_repurpose_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('network', 32);
            $table->string('source', 32)->default('linkedin');
            $table->string('title', 160);
            $table->longText('rules');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source', 'network']);
        });

        // An Account's own rules on top of a preset, never instead of it.
        Schema::create('tantra_repurpose_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('network', 32);
            $table->text('rules');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['account_id', 'network']);
        });

        // Which network a post moves to in which week after it first went out.
        Schema::create('tantra_repurpose_cadences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('sequence'); // [{network, week, enabled}]
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['account_id', 'workspace_id']);
        });

        // Where each original post has been taken, so nothing is repurposed twice.
        Schema::create('tantra_repurpose_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_review_item_id')->index();
            $table->string('network', 32);
            $table->string('status', 16); // queued, ready, in_review, done, skipped
            $table->string('via', 16); // cadence, repost
            $table->uuid('agent_job_id')->nullable();
            $table->uuid('idea_id')->nullable();
            $table->uuid('review_item_id')->nullable();
            $table->timestamps();
            $table->unique(['source_review_item_id', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_repurpose_progress');
        Schema::dropIfExists('tantra_repurpose_cadences');
        Schema::dropIfExists('tantra_repurpose_rules');
        Schema::dropIfExists('tantra_repurpose_presets');
    }
};
