<?php

declare(strict_types=1);

namespace App\Tantra\Repurpose;

use App\Models\Idea;
use App\Models\User;
use App\Tantra\Agent\AgentJob;
use App\Tantra\Agent\InvalidResult;
use App\Tantra\Agent\JobApplier;
use App\Tantra\Ideas\IdeaToReview;
use App\Tantra\Ideas\TantraIdeas;
use App\Tantra\Ideas\UpsertIdeas;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * tantra: applies repost and repurpose results (PRD 25.10). Every version
 * becomes an idea with its phone preview (external key job-<id>-<n>, so a
 * result applied twice changes nothing). A Repost to a connected channel goes
 * straight to Review; the weekly repurpose waits in Ideas, connected or not.
 */
final class RepostApplier implements JobApplier
{
    public function apply(AgentJob $job, array $result): string
    {
        $v = Validator::make($result, [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.target' => ['required', 'string', 'max:64'],
            'items.*.text' => ['required', 'string', 'max:10000'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.first_comment' => ['nullable', 'string', 'max:3000'],
            'items.*.thread' => ['nullable', 'array', 'max:25'],
            'items.*.thread.*' => ['string', 'max:3000'],
            'items.*.media' => ['nullable', 'array'],
            'items.*.planned_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($v->fails()) {
            throw new InvalidResult($v->errors()->first());
        }

        $input = $job->input ?? [];
        $targets = collect($input['targets'] ?? [])->keyBy('target');
        $source = ReviewItem::query()->find(data_get($input, 'source.review_item_id'));
        $sourceFiles = array_values((array) ($source?->media_manifest['previews'] ?? []));
        $user = User::query()->find($job->requested_by);
        if ($source === null || $user === null) {
            throw new InvalidResult('The post or the person who asked for this is gone.');
        }

        $toReview = $waiting = 0;
        foreach (array_values($result['items']) as $n => $item) {
            $target = $targets->get($item['target']);
            if ($target === null) {
                throw new InvalidResult("Item {$n} names a target that was not asked for: {$item['target']}.");
            }
            $platform = in_array($target['platform'], ['zernio-linkedin-page', 'linkedin-page'], true) ? 'linkedin_page' : $target['network'];
            $files = collect((array) data_get($item, 'media.files', []))
                ->map(fn ($f) => is_string($f) && preg_match('/^source:(\d+)$/', $f, $m) ? ($sourceFiles[(int) $m[1]] ?? null) : $f)
                ->filter(fn ($f) => is_string($f) && preg_match(TantraIdeas::FILE_PATTERN, $f))->values()->all();
            $text = (string) $item['text'];

            try {
                $rows = UpsertIdeas::execute($user, [[
                'external_key' => "job-{$job->id}-{$n}",
                'workspace_id' => $target['workspace_id'] ?? $source->workspace_id,
                'stage' => $job->kind === 'repurpose' ? 'Repurposed' : 'Reposts',
                'platform' => $platform,
                'channel_id' => $target['channel_id'] ?? null,
                'kind' => $job->kind === 'repurpose' ? 'repurpose' : 'repost',
                'title' => $item['title'] ?? mb_substr(strtok($text, "\n") ?: $text, 0, 120),
                'hook' => mb_substr(strtok($text, "\n") ?: $text, 0, 2000),
                'body' => trim(($item['notes'] ?? '').(filled(data_get($item, 'media.render')) ? "\n\nTo render: ".data_get($item, 'media.render') : '')) ?: null,
                'preview' => array_filter([
                    'text' => $text, 'first_comment' => $item['first_comment'] ?? null, 'thread' => $item['thread'] ?? [],
                    'media' => array_filter(['type' => data_get($item, 'media.type'), 'files' => $files]) ?: null,
                ], fn ($x) => $x !== null && $x !== []),
                'source_review_item_id' => $source->id,
                'cadence_week' => $input['cadence_week'] ?? null,
                'target_date' => $item['planned_date'] ?? null,
                'agent_job_id' => $job->id,
            ]]);
            } catch (ValidationException $e) {
                throw new InvalidResult("Item {$n}: ".collect($e->errors())->flatten()->first());
            }
            $ideaId = data_get($rows, '0.idea.id');
            $reviewItemId = null;
            if ($ideaId && $job->kind === 'repost' && ($target['connected'] ?? false)) {
                try {
                    $reviewItemId = data_get(IdeaToReview::execute($user, Idea::query()->findOrFail($ideaId)), 'review_item_id');
                } catch (Throwable) {
                    $reviewItemId = null; // stays in Ideas with its reason; nothing is lost
                }
            }
            $reviewItemId ? $toReview++ : $waiting++;

            if (! ($target['rework'] ?? false)) {
                RepurposeProgress::query()->updateOrCreate(
                    ['source_review_item_id' => $source->id, 'network' => $target['network']],
                    ['account_id' => $source->account_id, 'via' => $job->kind === 'repurpose' ? 'cadence' : 'repost',
                        'agent_job_id' => $job->id, 'idea_id' => $ideaId, 'review_item_id' => $reviewItemId,
                        'status' => $reviewItemId ? 'in_review' : 'ready'],
                );
            }
        }

        return trim(($toReview ? "{$toReview} sent to review. " : '').($waiting ? "{$waiting} ready in Ideas." : ''));
    }
}
