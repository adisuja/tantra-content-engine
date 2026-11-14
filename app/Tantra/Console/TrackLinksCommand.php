<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Links\CrmTracking;
use App\Tantra\Links\PieceLinks;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * tantra: every piece still open for review (draft, awaiting approval or
 * scheduled posts, review items not yet published, ideas) gets a tracked CRM
 * short link in place of each untracked link. Run once to backfill, then every
 * 10 minutes as the safety net behind the save hooks. Logs to
 * storage/app/tantra/link-rewrites.csv.
 */
class TrackLinksCommand extends Command
{
    protected $signature = 'tantra:track-links {--quiet-summary : Print nothing when nothing changed}';

    protected $description = 'Replace untracked links in open content with CRM short links, per piece and person';

    public function handle(CrmTracking $crm): int
    {
        if (! PieceLinks::enabled()) {
            $this->warn('Link tracking is off: TANTRA_CRM_API_KEY is not set, or TANTRA_CRM_LINKS=false.');

            return self::SUCCESS;
        }

        $links = new PieceLinks($crm);
        $open = [PostStatus::Draft, PostStatus::PendingApproval, PostStatus::Scheduled];
        Post::query()->whereIn('status', $open)->orderBy('created_at')->each(function (Post $post) use ($links): void {
            if (PieceLinks::hasUntracked((string) $post->content, json_encode(data_get($post->meta, 'thread_replies', []), JSON_UNESCAPED_SLASHES) ?: '')) {
                $links->post($post);
            }
        });
        ReviewItem::query()->whereNotIn('review_status', self::closedStatuses())->orderBy('created_at')->each(function (ReviewItem $item) use ($links): void {
            $links->reviewItem($item);
        });
        IdeaMeta::query()->where('state', IdeaMeta::STATE_IDEA)->orderBy('created_at')->each(function (IdeaMeta $meta) use ($links): void {
            $links->idea($meta);
        });

        if ($links->changes === [] && $links->errors === [] && $this->option('quiet-summary')) {
            return self::SUCCESS;
        }

        $byMember = collect($links->changes)->countBy('member')->sortKeys();
        $this->info(count($links->changes).' links replaced.');
        $this->table(['Member', 'Links replaced'], $byMember->map(fn (int $n, string $m): array => [$m, $n])->values()->all());
        if ($links->changes !== []) {
            $this->line('Log: '.Storage::disk('local')->path(PieceLinks::LOG));
        }
        foreach ($links->errors as $error) {
            $this->error($error);
        }

        return $links->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private static function closedStatuses(): array
    {
        return [ReviewStatus::Published->value, ReviewStatus::Rejected->value, ReviewStatus::Superseded->value];
    }
}
