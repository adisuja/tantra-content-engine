<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: every comment on a giveaway post gets an answer, and everyone answered
// lands on the post's "DMs to send" list (PRD 10.5 step 6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_giveaway_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('review_item_id')->constrained('tantra_review_items')->cascadeOnDelete();
            $table->string('comment_id');
            $table->string('commenter_name')->nullable();
            $table->text('commenter_url')->nullable();
            $table->text('comment_text')->nullable();
            $table->text('reply_text')->nullable();
            $table->string('reply_id')->nullable();
            $table->string('status')->default('replied');
            $table->text('error')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('dm_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_giveaway_replies');
    }
};
