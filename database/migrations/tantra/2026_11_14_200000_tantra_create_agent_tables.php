<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: agent jobs worked by our own Claude Code (PRD 25.4, 25.5). Admin only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_claude_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('kind', 16); // cloud_task, local_cli, api_key
            $table->text('fire_url')->nullable(); // encrypted
            $table->text('secret')->nullable(); // encrypted: the task's token or an API key
            $table->string('config_dir', 120)->nullable(); // local_cli: CLAUDE_CONFIG_DIR on the runner's machine
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_result', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('tantra_agent_runners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('kind', 16); // cloud_task, local
            $table->foreignUuid('claude_account_id')->nullable()->constrained('tantra_claude_accounts')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->json('kinds')->nullable(); // job kinds it takes; null = all
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tantra_agent_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 32);
            $table->string('status', 16)->index();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableUuidMorphs('subject');
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('error')->nullable();
            $table->json('log')->nullable();
            $table->foreignUuid('runner_id')->nullable()->constrained('tantra_agent_runners')->nullOnDelete();
            $table->string('claim_token', 64)->nullable(); // the current claim; a stale run's calls are refused
            $table->foreignUuid('claude_account_id')->nullable()->constrained('tantra_claude_accounts')->nullOnDelete();
            $table->string('session_url', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('dispatches')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('purge_after')->nullable()->index();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_agent_jobs');
        Schema::dropIfExists('tantra_agent_runners');
        Schema::dropIfExists('tantra_claude_accounts');
    }
};
