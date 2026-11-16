<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: a post deleted from its network through the Tantra app (POST publish/delete, D33) is kept for the Engine's own
// history, marked here, and hidden from every list by a global scope on Post. It is never published again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'deleted_by']);
        });
    }
};
