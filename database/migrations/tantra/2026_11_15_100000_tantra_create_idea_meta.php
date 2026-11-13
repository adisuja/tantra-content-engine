<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: ideas extended (PRD 25.8). One row per Tantra idea next to the
// upstream idea: its network, phone preview, kind, cadence week and the review
// item it became. Upserts find it by external_key, unique per Account.
//
// Only idea_id and account_id are foreign keys. The links to channels, posts
// and review items are plain ids the code checks on read: a SET NULL from a
// channel or a review item, cascading in the same workspace delete as the
// idea row itself, fails on MySQL (see .ai/rules/migrations.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_idea_meta', function (Blueprint $table): void {
            $table->foreignUuid('idea_id')->primary()->constrained('ideas')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('external_key', 128);
            $table->string('profile_key', 64)->nullable()->index();
            $table->string('platform', 32)->index();
            $table->uuid('channel_id')->nullable()->index();
            $table->string('format')->nullable();
            $table->string('template_key', 64)->nullable();
            $table->text('hook')->nullable();
            $table->json('preview')->nullable();
            // Full-quality files from POST /api/tantra/uploads, held until the idea goes to review.
            $table->json('upload_tokens')->nullable();
            $table->string('kind', 16)->default('new')->index();
            $table->uuid('source_review_item_id')->nullable()->index();
            $table->uuid('source_post_id')->nullable()->index();
            $table->unsignedSmallInteger('cadence_week')->nullable();
            $table->date('target_date')->nullable();
            // The agent job that wrote it. No foreign key until R2 adds tantra_agent_jobs.
            $table->uuid('agent_job_id')->nullable()->index();
            $table->string('state', 16)->default('idea')->index();
            $table->uuid('review_item_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['account_id', 'external_key'], 'tantra_idea_meta_external_unique');
        });

        // The other half of the link: the idea a review item came from.
        Schema::table('tantra_review_items', function (Blueprint $table): void {
            $table->uuid('idea_id')->nullable()->after('parent_review_item_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tantra_review_items', function (Blueprint $table): void {
            $table->dropIndex(['idea_id']);
            $table->dropColumn('idea_id');
        });

        Schema::dropIfExists('tantra_idea_meta');
    }
};
