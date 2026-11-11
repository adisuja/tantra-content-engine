<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: post and page numbers over time (PRD section 9 and 10.6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_metric_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('review_item_id')->nullable()->constrained('tantra_review_items')->nullOnDelete();
            $table->string('source'); // zernio, unipile
            $table->timestamp('captured_at');
            $table->unsignedInteger('impressions')->nullable();
            $table->unsignedInteger('unique_impressions')->nullable();
            $table->unsignedInteger('reactions')->nullable();
            $table->unsignedInteger('comments')->nullable();
            $table->unsignedInteger('shares')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->unsignedInteger('saves')->nullable();
            $table->decimal('engagement_rate', 7, 4)->nullable(); // ours: (reactions + comments + shares) / impressions
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'captured_at']);
        });

        Schema::create('tantra_account_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('followers')->nullable();
            $table->integer('follower_gain')->nullable();
            $table->unsignedInteger('page_views')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_account_snapshots');
        Schema::dropIfExists('tantra_metric_snapshots');
    }
};
