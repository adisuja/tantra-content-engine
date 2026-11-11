<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: the review layer (PRD section 9): per-workspace settings, imported
// engine batches, one review item per engine post, and an append-only event log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_workspace_settings', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('review_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('brand_profile_key', 64)->nullable()->unique();
            $table->string('default_time', 5)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('boost_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('tantra_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('engine_batch_id', 32);
            $table->string('title')->nullable();
            $table->string('dates_label')->nullable();
            $table->foreignUuid('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->string('status', 32)->default('in_review');
            $table->timestamps();

            $table->unique(['workspace_id', 'engine_batch_id']);
        });

        Schema::create('tantra_review_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->constrained('tantra_batches')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('engine_batch_id', 32);
            $table->string('engine_post_id', 32);
            $table->uuid('parent_review_item_id')->nullable()->index();
            $table->string('platform', 32);
            $table->uuid('post_group_id')->nullable()->index();
            $table->string('source', 16)->default('engine');
            $table->boolean('assisted_manual')->default(false);
            $table->date('planned_date')->nullable();
            $table->string('planned_time', 5)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('format')->nullable();
            $table->string('pillar')->nullable();
            $table->string('short')->nullable();
            $table->text('idea')->nullable();
            $table->string('sample_id', 32)->nullable();
            $table->string('sample_creator')->nullable();
            $table->string('design_system')->nullable();
            $table->text('copy_basis')->nullable();
            $table->json('numbers_for_signoff')->nullable();
            $table->json('sources')->nullable();
            $table->json('flags')->nullable();
            $table->date('cta_date')->nullable();
            $table->json('media_manifest')->nullable();
            $table->json('payload')->nullable();
            $table->string('review_status', 32)->default('in_review')->index();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('slot_fallback')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'engine_batch_id', 'engine_post_id'], 'tantra_review_items_engine_unique');
        });

        Schema::create('tantra_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_item_id')->constrained('tantra_review_items')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->text('body')->nullable();
            $table->json('value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['review_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_review_events');
        Schema::dropIfExists('tantra_review_items');
        Schema::dropIfExists('tantra_batches');
        Schema::dropIfExists('tantra_workspace_settings');
    }
};
