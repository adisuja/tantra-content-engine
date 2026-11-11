<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: team engagement (PRD 9 and 10.5): who posts from which Unipile account
// with their consent, and every comment, reply and reaction a boost run posts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_member_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('unipile_account_id')->nullable()->unique();
            $table->string('unipile_name')->nullable();
            $table->string('unipile_match_method')->nullable();
            $table->foreignUuid('unipile_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unipile_confirmed_at')->nullable();
            $table->timestamp('boost_consent_at')->nullable();
            $table->text('expertise')->nullable();
            $table->unsignedSmallInteger('daily_boost_cap')->default(15);
            $table->timestamp('boost_paused_until')->nullable();
            $table->timestamps();
        });

        Schema::create('tantra_boost_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->unique()->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('review_item_id')->constrained('tantra_review_items')->cascadeOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->string('unipile_post_ref')->nullable();
            $table->timestamp('window_start_at')->nullable();
            $table->unsignedSmallInteger('window_seconds')->nullable();
            $table->string('status')->default('waiting_lookup');
            $table->text('skipped_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('tantra_boost_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('tantra_boost_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('entry_index')->nullable();
            $table->uuid('parent_action_id')->nullable()->index();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('profile_key')->nullable();
            $table->string('unipile_account_id')->nullable();
            $table->string('kind');
            $table->string('reaction_type')->nullable();
            $table->text('text')->nullable();
            $table->unsignedInteger('offset_seconds')->default(0);
            $table->timestamp('run_at')->nullable();
            $table->string('status')->default('queued');
            $table->string('unipile_ref')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index(['unipile_account_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_boost_actions');
        Schema::dropIfExists('tantra_boost_runs');
        Schema::dropIfExists('tantra_member_profiles');
    }
};
