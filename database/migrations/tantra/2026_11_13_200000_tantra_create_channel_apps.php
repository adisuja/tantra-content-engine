<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: the Channels section of the Connectors tab. One row per developer
// app (Meta, Google, X, ...): the keys that make a network connectable.
// Instance wide, like the OAuth callback URLs. Encrypted with the app key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_channel_apps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('app')->unique(); // a key of ChannelApps::catalog()
            $table->text('credentials'); // encrypted JSON: field => value
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_channel_apps');
    }
};
