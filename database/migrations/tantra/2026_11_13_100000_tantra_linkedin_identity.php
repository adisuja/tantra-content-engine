<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: each person's LinkedIn identity, read from LinkedIn itself through
// their connected Unipile account (2026-11-13): never typed in by hand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantra_member_profiles', function (Blueprint $table) {
            $table->string('unipile_status', 32)->nullable()->after('unipile_confirmed_at');
            $table->string('linkedin_member_id')->nullable()->index()->after('unipile_status');
            $table->string('linkedin_public_identifier')->nullable()->after('linkedin_member_id');
            $table->string('linkedin_name')->nullable()->after('linkedin_public_identifier');
            $table->string('linkedin_headline', 500)->nullable()->after('linkedin_name');
            $table->string('linkedin_profile_url')->nullable()->after('linkedin_headline');
            $table->unsignedInteger('linkedin_followers')->nullable()->after('linkedin_profile_url');
            $table->string('linkedin_picture_path')->nullable()->after('linkedin_followers');
            $table->text('linkedin_picture_source')->nullable()->after('linkedin_picture_path');
            $table->timestamp('linkedin_synced_at')->nullable()->after('linkedin_picture_source');
        });
    }

    public function down(): void
    {
        Schema::table('tantra_member_profiles', function (Blueprint $table) {
            $table->dropIndex(['linkedin_member_id']);
            $table->dropColumn(['unipile_status', 'linkedin_member_id', 'linkedin_public_identifier', 'linkedin_name', 'linkedin_headline',
                'linkedin_profile_url', 'linkedin_followers', 'linkedin_picture_path', 'linkedin_picture_source', 'linkedin_synced_at']);
        });
    }
};
