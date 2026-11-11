<?php

declare(strict_types=1);

namespace App\Tantra\Mcp;

use App\Models\Workspace;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\ReviewGate;
use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * tantra: shared by the Tantra MCP tools (PRD 10.7). Everything is bound to the
 * token's current workspace, and every write goes through the same action as
 * the web screen, so the rules (review owner only, no dashes, not after
 * publishing) are the same everywhere.
 */
trait TantraMcp
{
    protected function workspaceOf(Request $request): ?Workspace
    {
        return $request->user()?->currentWorkspace;
    }

    protected function itemOf(Request $request): ?ReviewItem
    {
        $id = $request->validate(['review_item_id' => ['required', 'uuid']])['review_item_id'];
        $workspace = $this->workspaceOf($request);
        $item = $workspace ? ReviewItem::query()->where('workspace_id', $workspace->id)->find($id) : null;

        return $item !== null && ReviewGate::canView($request->user(), $item) ? $item : null;
    }

    /** Runs a write and turns the action's validation message into a tool error. */
    protected function attempt(Closure $write): ?Response
    {
        try {
            $write();
        } catch (ValidationException $e) {
            return Response::error((string) collect($e->errors())->flatten()->first());
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected function summary(ReviewItem $item): array
    {
        return [
            'id' => $item->id,
            'engine_post_id' => $item->engine_post_id,
            'batch' => $item->engine_batch_id,
            'platform' => $item->platform,
            'status' => $item->review_status->value,
            'planned' => trim(($item->planned_date?->toDateString() ?? '').' '.($item->planned_time ?? '')) ?: null,
            'format' => $item->format,
            'pillar' => $item->pillar,
            'short' => $item->short,
            'revision' => $item->revision,
            'assisted_manual' => (bool) $item->assisted_manual,
        ];
    }
}
