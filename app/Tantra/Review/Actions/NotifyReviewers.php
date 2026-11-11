<?php

declare(strict_types=1);

namespace App\Tantra\Review\Actions;

use App\Models\User;
use App\Tantra\Review\Mail\NumbersToSignOff;
use App\Tantra\Review\Mail\ReviewReady;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * tantra: after an import, one email per workspace to its review owner, and one
 * to Adi for numbers of his quoted in a teammate's posts (PRD 10.1 and 10.2).
 */
final class NotifyReviewers
{
    /** @param list<array<string, mixed>> $results ImportBatch results */
    public static function afterImport(array $results): void
    {
        $ids = collect($results)
            ->filter(fn (array $r): bool => in_array($r['outcome'] ?? null, ['created', 'updated'], true))
            ->pluck('review_item_id');

        if ($ids->isEmpty()) {
            return;
        }

        $items = ReviewItem::query()->whereIn('id', $ids)->with('workspace.account')->get();

        foreach ($items->groupBy('batch_id') as $batchId => $group) {
            /** @var ReviewItem $first */
            $first = $group->first();
            $workspace = $first->workspace;
            $reviewer = User::query()->find(ReviewGate::reviewOwnerId($first));
            $sorted = $group->sortBy(fn (ReviewItem $i): string => ($i->planned_date?->toDateString() ?? '9999').$i->engine_post_id)->values();

            if ($reviewer !== null) {
                $earliest = $sorted->first()?->planned_date;
                $week = $earliest ? CarbonImmutable::parse($earliest->toDateString())->startOfWeek()->format('j M') : now()->startOfWeek()->format('j M');

                Mail::to($reviewer)->queue(new ReviewReady(
                    (string) $batchId,
                    (string) $workspace?->name,
                    $week,
                    $sorted->map(fn (ReviewItem $i): array => [
                        'id' => $i->engine_post_id,
                        'date' => $i->planned_date?->toDateString(),
                        'short' => $i->short,
                        'revised' => $i->revision > 1,
                    ])->all(),
                ));
            }

            $adi = User::query()->find($workspace?->account?->owner_id);
            if ($adi === null || $adi->id === $reviewer?->id) {
                continue;
            }

            $quoting = $sorted
                ->map(fn (ReviewItem $i): array => [
                    'id' => $i->engine_post_id,
                    'date' => $i->planned_date?->toDateString(),
                    'short' => $i->short,
                    'numbers' => collect($i->numbers_for_signoff ?? [])
                        ->filter(fn (array $n): bool => ($n['signer'] ?? null) === 'adi' && blank($n['signed_at'] ?? null))
                        ->pluck('text')->values()->all(),
                ])
                ->filter(fn (array $p): bool => $p['numbers'] !== [])
                ->values();

            if ($quoting->isNotEmpty()) {
                Mail::to($adi)->queue(new NumbersToSignOff((string) $batchId, (string) $workspace?->name, $quoting->all()));
            }
        }
    }
}
