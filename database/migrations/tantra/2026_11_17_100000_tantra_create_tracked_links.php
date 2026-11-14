<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: one row per CRM short link the Engine made, for a piece, a person, a
// platform and a placement. It is the link log, keeps a re-run from making a
// second link, and ties the CRM's clicks and conversions back to people and pieces.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_tracked_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_key', 40);
            // The piece: a review item, else the post, else the idea.
            $table->string('piece_ref', 80);
            $table->string('piece_name', 200)->nullable();
            $table->string('platform', 30);
            $table->string('placement', 40);
            $table->string('medium', 20);
            $table->string('code', 40);
            $table->text('destination');
            $table->text('short_url')->nullable();
            $table->text('long_url')->nullable();
            // false: a LinkedIn post body link that needs a preview card, so the full UTM link is used.
            $table->boolean('shortened')->default(true);
            $table->string('crm_link_id', 40)->nullable();
            $table->string('crm_asset_id', 40)->nullable();
            $table->string('crm_channel_id', 40)->nullable();
            $table->timestamps();
            $table->unique(['member_key', 'piece_ref', 'platform', 'placement'], 'tantra_tracked_links_piece');
            $table->index(['workspace_id', 'member_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_tracked_links');
    }
};
