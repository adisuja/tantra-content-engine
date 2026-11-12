<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: the Connectors tab. One row per Account and provider: the AI
// providers every AI feature runs on, the Claude and Codex subscriptions, and
// the Tantra API key. Keys are encrypted with the app key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_ai_connectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('provider'); // a key of ProviderCatalog::all()
            $table->text('secret')->nullable(); // encrypted: API key, setup token or Codex auth.json
            $table->string('secret_last_four', 8)->nullable();
            $table->string('base_url')->nullable();
            $table->string('selected_model')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_ai_connectors');
    }
};
