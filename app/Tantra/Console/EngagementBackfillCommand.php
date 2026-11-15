<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Models\Post;
use App\Tantra\Engagement\PublishedPost;
use Illuminate\Console\Command;

/**
 * tantra: send the last N days of published posts to Tantra's Engagement once (One Tantra R1). --dry-run lists them
 * and sends nothing; run it first. Each post is one engine.post_published event with a stable id, so running it twice
 * sends each post once, and a post that Engagement already watches is only updated (never switched back on).
 */
class EngagementBackfillCommand extends Command
{
    protected $signature = 'tantra:engagement-backfill {--days=30 : How far back} {--dry-run : List the posts and send nothing}';

    protected $description = 'Send recent published posts to Tantra Engagement as watched posts (list first with --dry-run)';

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $posts = PublishedPost::recent($days);
        $dry = (bool) $this->option('dry-run');

        $this->table(['Published', 'Platform', 'Account', 'Giveaway', 'Link'], $posts->map(fn (Post $p): array => [
            $p->published_at?->toDateTimeString(),
            $p->platform?->value,
            $p->socialAccount?->display_name ?? $p->platform_name,
            PublishedPost::payload($p)['keyword'] ?? '',
            $p->platform_url,
        ])->all());

        if ($dry) {
            $this->components->info("{$posts->count()} posts from the last {$days} days would be sent. Nothing was sent (dry run).");

            return self::SUCCESS;
        }
        if (! PublishedPost::enabled()) {
            $this->components->error('The CRM is not connected (TANTRA_CRM_URL, TANTRA_CRM_INTAKE_KEY) or TANTRA_CRM_ENGAGEMENT_POSTS is off.');

            return self::FAILURE;
        }

        $sent = $posts->filter(fn (Post $p): bool => PublishedPost::send($p, 'backfill'))->count();
        $this->components->info("{$sent} of {$posts->count()} posts queued for Engagement.");

        return self::SUCCESS;
    }
}
