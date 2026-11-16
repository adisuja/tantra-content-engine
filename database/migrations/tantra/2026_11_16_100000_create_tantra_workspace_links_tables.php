<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: PV-02. Which Engine workspace is each Tantra team's (written by POST /api/tantra/provision), and who provisioning added.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_workspace_links', function (Blueprint $table): void {
            $table->id();
            $table->string('tantra_workspace_id', 100)->unique();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('name', 120);
            // True when provisioning made the Engine workspace (so only then may it rename it).
            $table->boolean('made_workspace')->default(false);
            $table->timestamps();
        });

        Schema::create('tantra_workspace_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('link_id')->constrained('tantra_workspace_links')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('clerk_user_id', 100);
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['link_id', 'user_id']);
            $table->unique(['link_id', 'clerk_user_id']);
            $table->index('clerk_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_workspace_people');
        Schema::dropIfExists('tantra_workspace_links');
    }
};
