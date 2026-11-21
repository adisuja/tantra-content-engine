<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tantra: Speed P3. The last version sent to the CRM for each thing its Content mirror holds (a post, a review item, a channel, a
// workspace's metrics), so every change goes out with a higher number than the one before (App\Tantra\Notify\Mirror\Versions).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_mirror_versions', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->unsignedBigInteger('version');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_mirror_versions');
    }
};
