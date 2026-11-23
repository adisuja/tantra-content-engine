<?php

declare(strict_types=1);

namespace App\Tantra\Console;

use App\Tantra\Engagement\EngagementChannels;
use App\Tantra\Provision\TantraWorkspaceLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * tantra: M1 open access (23 Nov, PRD_2026_11_23 section 2.4). Every member of a workspace a Tantra team maps to
 * (TantraWorkspaceLink, and the ENGINE_CHANNELS_WORKSPACES fallback) posts without approval. Idempotent: a second
 * run finds nothing to change. Admins and team management are untouched.
 */
class MembersOpenAccessCommand extends Command
{
    protected $signature = 'tantra:members-open-access {--dry-run : Only count the members that would change}';

    protected $description = 'Clear requires_approval for every member of Tantra mapped workspaces (M1 open access)';

    public function handle(): int
    {
        $ids = TantraWorkspaceLink::query()->pluck('workspace_id')->map(fn ($id): string => (string) $id)
            ->merge(collect(EngagementChannels::workspaceMap())->flatten()->filter(fn ($id): bool => is_string($id) && Str::isUuid($id)))
            ->unique()->values()->all();

        if ($ids === []) {
            $this->components->info('No Tantra mapped workspaces.');

            return self::SUCCESS;
        }

        $query = DB::table('user_workspace')->whereIn('workspace_id', $ids)->where('requires_approval', true);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->components->info("{$count} member(s) in ".count($ids).' mapped workspace(s) would post without approval.');

            return self::SUCCESS;
        }

        $changed = $query->update(['requires_approval' => false]);
        $this->components->info("{$changed} member(s) in ".count($ids).' mapped workspace(s) now post without approval.');

        return self::SUCCESS;
    }
}
