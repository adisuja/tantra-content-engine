<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: Content OS plan state per workspace (App\Tantra\Billing), and every
// Dodo webhook seen once, by its webhook-id, so a retry never applies twice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_workspace_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan', 30)->default('content_os');
            // none, active, on_hold, cancelled, expired, failed, comped
            $table->string('status', 20)->default('none');
            $table->string('dodo_customer_id', 64)->nullable();
            $table->string('dodo_subscription_id', 64)->nullable()->index();
            $table->string('billing_email')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('payment_failed_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->unsignedTinyInteger('dunning_step')->default(0);
            $table->timestamp('renewal_notice_for')->nullable();
            $table->timestamp('paused_notice_at')->nullable();
            $table->timestamp('winback_sent_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tantra_billing_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('webhook_id', 100)->unique();
            $table->string('type', 60);
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('outcome', 30)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_billing_events');
        Schema::dropIfExists('tantra_workspace_plans');
    }
};
