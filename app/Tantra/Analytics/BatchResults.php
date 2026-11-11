<?php

declare(strict_types=1);

namespace App\Tantra\Analytics;

use App\Models\Post;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Collection;

/**
 * tantra: a batch's numbers in the `results.json` shape from
 * knowledge/content/analytics.md (PRD 10.6), so the batch prompt's Keep,
 * Change, Repeat step runs from Engine data. A missing number is null with
 * the reason in source_notes, never 0.
 */
final class BatchResults
{
    public const METRICS = ['impressions', 'unique_impressions', 'clicks', 'reactions', 'comments', 'shares'];

    /** @return array{batch: string, generated_at: string, posts: list<array<string, mixed>>, by_format: array<string, mixed>, by_pillar: array<string, mixed>, by_profile: array<string, mixed>} */
    public static function for(string $accountId, string $engineBatchId): array
    {
        $items = ReviewItem::query()
            ->where('account_id', $accountId)
            ->where('engine_batch_id', $engineBatchId)
            ->where('review_status', '!=', ReviewStatus::Superseded->value)
            ->orderBy('engine_post_id')
            ->get();

        $profiles = WorkspaceSettings::query()->whereIn('workspace_id', $items->pluck('workspace_id')->unique())->pluck('brand_profile_key', 'workspace_id');
        $posts = $items->map(fn (ReviewItem $item): array => self::post($item, $profiles[$item->workspace_id] ?? null))->values();

        return [
            'batch' => $engineBatchId,
            'generated_at' => now()->toIso8601ZuluString(),
            'posts' => $posts->all(),
            'by_format' => self::rollUp($posts, 'format'),
            'by_pillar' => self::rollUp($posts, 'pillar'),
            'by_profile' => self::rollUp($posts, 'profile'),
        ];
    }

    /** @return array<string, mixed> */
    private static function post(ReviewItem $item, ?string $profile): array
    {
        $row = [
            'key' => $item->engine_post_id,
            'profile' => $profile,
            'platform' => $item->platform,
            'format' => $item->format,
            'pillar' => $item->pillar,
            'sample' => $item->sample_id,
            'published_at' => null,
            'url' => null,
            ...array_fill_keys(self::METRICS, null),
            'engagement_rate' => null,
            'registrations' => null,
            'source_notes' => ['registrations: counted from the funnel by the batch prompt'],
        ];

        if ($item->assisted_manual) {
            $task = ManualTask::query()->where('review_item_id', $item->id)->first();
            $row['url'] = $task?->posted_url;
            $row['published_at'] = $task?->posted_at?->toIso8601ZuluString();
            $row['source_notes'][] = 'posted by hand: no numbers in the Engine';

            return $row;
        }

        $post = $item->post_group_id ? Post::query()->where('post_group_id', $item->post_group_id)->whereNotNull('published_at')->orderBy('published_at')->first() : null;
        if ($post === null) {
            $row['source_notes'][] = 'not published yet ('.str_replace('_', ' ', $item->review_status->value).')';

            return $row;
        }

        $row['published_at'] = $post->published_at->toIso8601ZuluString();
        $row['url'] = $post->platform_url;

        $snap = MetricSnapshot::query()->where('post_id', $post->id)->latest('captured_at')->first();
        if ($snap === null) {
            $row['source_notes'][] = 'no numbers read yet';

            return $row;
        }

        foreach (self::METRICS as $metric) {
            $row[$metric] = $snap->{$metric};
        }
        $row['engagement_rate'] = $snap->engagement_rate;
        $row['source_notes'][] = "{$snap->source}, read ".$snap->captured_at->toIso8601ZuluString();
        if ($snap->source === 'unipile') {
            $row['source_notes'][] = 'impressions and clicks: not available for personal posts';
        }

        return $row;
    }

    /**
     * Totals and per post averages by a field. Averages count only posts that have
     * that number, and say how many did.
     *
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array<string, mixed>
     */
    private static function rollUp(Collection $posts, string $field): array
    {
        return $posts->groupBy(fn (array $p): string => (string) ($p[$field] ?? 'unknown'))
            ->map(function (Collection $group): array {
                $out = ['posts' => $group->count()];
                foreach ([...self::METRICS, 'engagement_rate'] as $metric) {
                    $values = $group->pluck($metric)->filter(fn ($v): bool => $v !== null);
                    $out[$metric] = [
                        'total' => $metric === 'engagement_rate' ? null : ($values->isEmpty() ? null : (int) $values->sum()),
                        'per_post' => $values->isEmpty() ? null : round((float) $values->avg(), $metric === 'engagement_rate' ? 4 : 1),
                        'posts_with_it' => $values->count(),
                    ];
                }

                return $out;
            })->sortKeys()->all();
    }
}
