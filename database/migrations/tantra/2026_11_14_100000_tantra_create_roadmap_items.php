<?php

declare(strict_types=1);

use App\Tantra\Roadmap\Column;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// tantra: the public product roadmap (/roadmap). Instance wide, like the
// Channels keys: one board for every product Tantra ships. The requester's
// email is private; only the owner, the write API and webhooks ever see it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantra_roadmap_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('column', 20)->default(Column::DEFAULT->value);
            $table->string('product', 40)->default('content-engine');
            $table->string('category', 40)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('source', 20)->default('team'); // team | request
            $table->string('requester_name', 120)->nullable();
            $table->string('requester_email', 190)->nullable();
            $table->string('requester_ip_hash', 64)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();

            $table->index(['product', 'column', 'position']);
        });

        // The work in flight on 14 Nov 2026. Plain DB inserts: a migration never
        // calls app code, so a later refactor cannot break an upgrade.
        $now = now();
        $items = [
            ['planned', 'Channels', 'TikTok', 'Post videos and photo carousels to TikTok from the calendar.', 'Needs a TikTok developer account and a posting worker outside India, where TikTok is blocked.'],
            ['in_progress', 'Channels', 'LinkedIn company pages on our own app', 'Post to LinkedIn company pages through Tantra\'s own Community Management app, with no third party in between.', 'We submitted the app for LinkedIn\'s review on 14 Nov 2026.'],
            ['in_progress', 'Publishing', 'YouTube long form', 'Long videos with playlists, tags and custom thumbnails, not only Shorts.', null],
            ['in_progress', 'Publishing', 'LinkedIn polls and 20 image posts', 'Polls and posts with up to 20 images on LinkedIn profiles and pages.', null],
            ['in_progress', 'Publishing', 'First comments everywhere', 'A first comment on Instagram, Facebook, Threads and YouTube posts, as we already do on LinkedIn.', null],
            ['planned', 'Engagement', 'One inbox for comment replies', 'Read and reply to comments from every channel in one place.', null],
            ['planned', 'Channels', 'Pinterest', 'Pin images and videos to your boards.', null],
            ['planned', 'Channels', 'Google Business Profile', 'Updates, offers and events on your Google Business Profile.', null],
            ['planned', 'Channels', 'Discord and Telegram', 'Post announcements to Discord channels and Telegram groups.', null],
            ['beta', 'Channels', 'Bluesky and Mastodon', 'Posts and threads on Bluesky and Mastodon.', 'Working today. Tell us what breaks.'],
            ['beta', 'Channels', 'X with pay per use credits', 'Post to X on X\'s pay per use API credits instead of a monthly plan.', 'Working today. Tell us what breaks.'],
            ['in_progress', 'Platform', 'Sign in with your Tantra account', 'One login for the Content Engine and the Tantra app.', null],
            ['planned', 'Platform', 'Merge into the Tantra app', 'The Content Engine becomes part of the Tantra app at usetantra.com.', null],
            ['shipped', 'Channels', 'Team LinkedIn profiles through Unipile', 'Teammates connect their own LinkedIn profiles and the engine posts and engages as them.', null],
            ['shipped', 'Platform', 'Channels across workspaces in the sidebar', 'Every channel you can use, in every workspace, one click away in the sidebar.', null],
        ];

        $positions = [];
        foreach ($items as [$column, $category, $title, $description, $detail]) {
            $positions[$column] = ($positions[$column] ?? 0) + 1;
            DB::table('tantra_roadmap_items')->insert([
                'id' => (string) Str::ulid(),
                'title' => $title,
                'description' => $detail === null ? $description : $description."\n\n".$detail,
                'column' => $column,
                'product' => 'content-engine',
                'category' => $category,
                'position' => $positions[$column],
                'source' => 'team',
                'shipped_at' => $column === 'shipped' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tantra_roadmap_items');
    }
};
