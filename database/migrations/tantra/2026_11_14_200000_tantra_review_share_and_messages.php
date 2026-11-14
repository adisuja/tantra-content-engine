<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: a public preview link per post (/previewlink/{token}) and the review
// owner's edits to the follow-up messages that send its resource:
// {linkedin_dm?, linkedin_connect?, email_subject?, email_body?, whatsapp?, sms?}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_review_items', function (Blueprint $table) {
            $table->string('preview_token', 64)->nullable()->unique();
            $table->json('message_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tantra_review_items', function (Blueprint $table) {
            $table->dropUnique(['preview_token']);
            $table->dropColumn(['preview_token', 'message_overrides']);
        });
    }
};
