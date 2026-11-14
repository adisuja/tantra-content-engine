<?php

declare(strict_types=1);

namespace App\Tantra;

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Events\PostStatusChanged;
use App\Http\Resources\App\HandleInertiaRequests\SidebarChannelResource;
use App\Models\Idea;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Agent\Admin;
use App\Tantra\Agent\Appliers;
use App\Tantra\Ai\AiAlerts;
use App\Tantra\Ai\CliTextGateway;
use App\Tantra\Boost\Boost;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\Boost\StartBoostRun;
use App\Tantra\Channels\ChannelApps;
use App\Tantra\Composer\MakeComposerThread;
use App\Tantra\Console\AgentJobsCommand;
use App\Tantra\Console\AiConnectorCommand;
use App\Tantra\Console\CheckConnectorsCommand;
use App\Tantra\Console\ClerkUnlinkCommand;
use App\Tantra\Console\CollectMetricsCommand;
use App\Tantra\Console\CreateOwnerCommand;
use App\Tantra\Console\GiveawayRepliesCommand;
use App\Tantra\Console\HealthCommand;
use App\Tantra\Console\HeartbeatCommand;
use App\Tantra\Console\InviteTeammateCommand;
use App\Tantra\Console\LinkedInSyncCommand;
use App\Tantra\Console\ManualTasksCommand;
use App\Tantra\Console\MapProfileCommand;
use App\Tantra\Console\ReconnectRemindersCommand;
use App\Tantra\Console\RepurposeCadenceCommand;
use App\Tantra\Console\SyncChannelsCommand;
use App\Tantra\Console\TeamAccountCommand;
use App\Tantra\Console\TeamAccountsCommand;
use App\Tantra\Console\ZernioConnectCommand;
use App\Tantra\Http\Controllers\Web\ReviewPageController;
use App\Tantra\Http\Middleware\EnsureOwnerToken;
use App\Tantra\Http\Middleware\EnsureRunnerToken;
use App\Tantra\Http\Middleware\EnsureTantraAdmin;
use App\Tantra\Http\Middleware\LandOnReview;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Manual\ManualTask;
use App\Tantra\Ops\LeanHorizon;
use App\Tantra\Rebrand\Rebrand;
use App\Tantra\Rebrand\RebrandApplier;
use App\Tantra\Repurpose\RepostApplier;
use App\Tantra\Review\Actions\DraftMaker;
use App\Tantra\Review\Listeners\RecordComposerEdit;
use App\Tantra\Review\Models\Batch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use App\Tantra\Review\OwnerApproval;
use App\Tantra\Review\ReviewChips;
use App\Tantra\Review\ReviewGate;
use App\Tantra\Templates\ContentTemplate;
use App\Tantra\Templates\TemplateFromPostApplier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;

/**
 * Entry point for everything Tantra adds to the TryPost fork.
 *
 * Kept in its own namespace so upstream merges stay clean. Registered with a
 * single line in bootstrap/providers.php.
 */
class TantraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ReviewChips::class);
    }

    public function boot(): void
    {
        // Our migrations live apart from upstream's, in database/migrations/tantra.
        $this->loadMigrationsFrom(database_path('migrations/tantra'));

        // Every network is listed; one is enabled once its developer app keys
        // are in (Settings > Connectors > Channels or the .env). Workers read
        // the keys again before each job, so a network set up later publishes
        // without a restart.
        ChannelApps::apply();
        Queue::before(fn () => ChannelApps::apply());
        // The connect dialog offers "Add the keys" only to the Account owner.
        Inertia::share('tantraChannels', fn (): array => [
            'canSetUp' => ChannelApps::canManage(Auth::user()),
        ]);
        $this->loadViewsFrom(__DIR__.'/views', 'tantra');

        $this->app->make(Router::class)->aliasMiddleware('tantra.owner-token', EnsureOwnerToken::class);
        $this->app->make(Router::class)->aliasMiddleware('tantra.admin', EnsureTantraAdmin::class);
        $this->app->make(Router::class)->aliasMiddleware('tantra.runner', EnsureRunnerToken::class);
        // People land on Review, not the calendar, in Tantra workspaces (PRD 10.2).
        $kernel = $this->app->make(HttpKernel::class);
        if ($kernel instanceof FoundationHttpKernel) {
            $kernel->appendMiddlewareToGroup('web', LandOnReview::class);
        }
        $this->loadRoutesFrom(__DIR__.'/Http/routes-api.php');
        $this->loadRoutesFrom(__DIR__.'/Http/routes-web.php');

        // Live review screens: anyone who may open the batch hears its changes.
        Broadcast::channel('tantra.review.batch.{batch}', fn (User $user, string $batch): bool => Batch::query()
            ->whereKey($batch)
            ->whereIn('workspace_id', ReviewPageController::visibleWorkspaceIds($user))
            ->exists());

        // A published post with an approved team thread starts its boost run (PRD 10.5).
        Event::listen(PostStatusChanged::class, function (PostStatusChanged $event): void {
            if ($event->post->status === PostStatus::Published && Boost::enabled()) {
                StartBoostRun::dispatch($event->post->id);
            }
            // A composer post has no engine thread: write one when it is scheduled (PRD M3).
            if ($event->post->status === PostStatus::Scheduled && Boost::enabled()) {
                MakeComposerThread::dispatch($event->post->id);
            }
        });

        // A teammate connecting LinkedIn turns their waiting posts into drafts.
        SocialAccount::created(fn (SocialAccount $channel) => DraftMaker::forWorkspace($channel));

        // Tantra ideas (PRD 25.8): an upstream idea's network, preview and review link.
        Idea::resolveRelationUsing('tantraMeta', fn (Idea $idea): HasOne => $idea->hasOne(IdeaMeta::class, 'idea_id'));

        // An edit in the composer is a new version of the post under review.
        Post::updated(fn (Post $post) => RecordComposerEdit::handle($post));

        // Approval by the review owner (PRD 10.8): drop the kept answers when they can change.
        WorkspaceSettings::saved(fn () => OwnerApproval::forget());
        WorkspaceSettings::deleted(fn () => OwnerApproval::forget());
        User::saved(function (User $user): void {
            if ($user->wasChanged(['password', 'google_id', 'github_id'])) {
                OwnerApproval::forget();
            }
        });

        // The Connectors tab: Claude Code and Codex run through their official CLIs.
        foreach (['tantra-claude-cli' => CliTextGateway::CLAUDE, 'tantra-codex-cli' => CliTextGateway::CODEX] as $driver => $cli) {
            Ai::extend($driver, fn ($app, array $config) => (new OpenAiCompatibleProvider($config + ['url' => 'http://localhost', 'key' => 'cli'], $app->make(Dispatcher::class)))
                ->useTextGateway(new CliTextGateway($cli, (string) ($config['cli_secret'] ?? ''), $config['connector_id'] ?? null)));
        }

        // AI and integration warnings for the app banner (Connectors tab).
        Inertia::share('tantraAlerts', fn (): array => Auth::user() === null ? [] : AiAlerts::forAccount(Auth::user()->account_id));

        // The sidebar's other workspaces and their channels (PRD D1): every channel the member can use.
        Inertia::share('tantraOtherChannels', fn (): array => Auth::user() === null ? [] : Auth::user()->accountWorkspaces()
            ->when(Auth::user()->current_workspace_id !== null, fn ($q) => $q->where('workspaces.id', '!=', Auth::user()->current_workspace_id))
            ->orderBy('name')->get()
            ->map(fn (Workspace $workspace): array => ['id' => $workspace->id, 'name' => $workspace->name, 'channels' => SidebarChannelResource::collection($workspace)])
            ->filter(fn (array $group): bool => $group['channels'] !== [])
            ->values()->all());

        // The sidebar's Review badge: posts waiting for this user's approval.
        // Things an agent job can be about (the app enforces a morph map).
        Relation::morphMap([
            'tantraContentTemplate' => ContentTemplate::class,
            'tantraReviewItem' => ReviewItem::class,
            'tantraMemberProfile' => MemberProfile::class,
            'tantraRebrand' => Rebrand::class,
        ]);

        // What the Engine does with each kind of agent job result (PRD 25.5).
        Appliers::register('template_from_post', TemplateFromPostApplier::class);
        Appliers::register('repost', RepostApplier::class);
        Appliers::register('repurpose', RepostApplier::class);
        Appliers::register('rebrand', RebrandApplier::class);

        // Studio ops in the sidebar for admins only (PRD 25.4).
        Inertia::share('tantraAdmin', fn (): bool => Admin::is(Auth::user()));

        Inertia::share('tantraReview', fn (): ?array => Auth::user() === null ? null : [
            'waiting' => ReviewGate::waitingCount(Auth::user()),
            'tasks_open' => ManualTask::query()->where('owner_user_id', Auth::id())->whereIn('status', ['waiting', 'due'])->count(),
            'tasks_due' => ManualTask::query()->where('owner_user_id', Auth::id())->where('status', 'due')->count(),
        ]);

        // Manual tasks (X, personal polls): time them, mark due, expire (PRD 12).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('tantra:manual-tasks')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('tantra:giveaway-replies')->hourly()->withoutOverlapping();
            // 09:11 in Kolkata: reconnect reminders for LinkedIn channels.
            $schedule->command('tantra:reconnect-reminders')->dailyAt('03:41');
            // The health report's scheduler tick, and the media-exists check for posts due in a day (PRD 8.5).
            $schedule->command('tantra:heartbeat')->everyMinute();
            $schedule->command('tantra:heartbeat --media')->everyFifteenMinutes()->withoutOverlapping();
            // Names, headlines and photos from LinkedIn: new accounts every 15 minutes, a refresh daily.
            // Agent jobs: re-fire unclaimed ones, requeue quiet ones, purge old inputs (PRD 25.5).
            $schedule->command('tantra:agent-jobs')->everyMinute()->withoutOverlapping();
            // Mondays 04:21 UTC: each published post moves to its next network (PRD 25.10).
            $schedule->command('tantra:repurpose-cadence')->weeklyOn(1, '04:21')->withoutOverlapping();
            $schedule->command('tantra:linkedin-sync')->everyFifteenMinutes()->withoutOverlapping();
            // Every account connected in Tantra becomes a channel (PRD engine channels, 2026-11-13).
            $schedule->command('tantra:sync-channels')->everyFifteenMinutes()->withoutOverlapping();
            // Post and page numbers (PRD 10.6).
            $schedule->command('tantra:check-connectors')->hourly()->withoutOverlapping();
            $schedule->command('tantra:collect-metrics')->twiceDaily(6, 18)->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([AiConnectorCommand::class, CheckConnectorsCommand::class, CollectMetricsCommand::class, CreateOwnerCommand::class, MapProfileCommand::class, InviteTeammateCommand::class, ZernioConnectCommand::class, ManualTasksCommand::class, TeamAccountsCommand::class, TeamAccountCommand::class, ClerkUnlinkCommand::class, LinkedInSyncCommand::class, AgentJobsCommand::class, RepurposeCadenceCommand::class, GiveawayRepliesCommand::class, HealthCommand::class, HeartbeatCommand::class, ReconnectRemindersCommand::class, SyncChannelsCommand::class]);
        }

        if ($this->app->environment('production') && config('tantra.lean_workers.enabled')) {
            $this->useLeanHorizon();
        }

        if (config('tantra.branding.enabled')) {
            $this->useTantraBranding();
        }
    }

    /**
     * Swaps TryPost's name and outward links for Tantra's wherever upstream
     * still holds its own default, so an explicit env value always wins.
     */
    private function useTantraBranding(): void
    {
        $name = (string) config('tantra.branding.name');
        $defaults = [
            'app.name' => ['TryPost.it', $name],
            'mail.from.name' => ['TryPost', $name],
            'trypost.user_agent' => ['TryPost.it/1.0 (+https://trypost.it)', (string) config('tantra.branding.user_agent')],
            // No Tantra terms or privacy pages exist for the Engine yet, so the
            // auth screens hide the sentence instead of linking to TryPost's.
            'trypost.legal.terms_url' => ['https://trypost.it/terms', ''],
            'trypost.legal.privacy_url' => ['https://trypost.it/privacy', ''],
        ];

        foreach ($defaults as $key => [$upstream, $tantra]) {
            if (config($key) === $upstream) {
                config([$key => $tantra]);
            }
        }

        Inertia::share('tantra', fn (): array => [
            'branding' => true,
            'name' => $name,
            'sourceUrl' => (string) config('tantra.branding.source_url'),
        ]);
    }

    private function useLeanHorizon(): void
    {
        $plan = LeanHorizon::plan(
            (array) config('horizon.defaults', []),
            // Team engagement first: its comments have to land within minutes.
            // Every network's queue: one set up later needs no restart.
            [Boost::QUEUE, ...Platform::allQueues()],
            (int) config('tantra.lean_workers.processes', 3),
        );

        // Horizon checks the supervisors named under every environment when
        // it starts (ProvisioningPlan), not only the current one, so each
        // environment must name only supervisors that still exist.
        $sizes = array_map(
            fn (array $supervisor): array => ['maxProcesses' => (int) ($supervisor['maxProcesses'] ?? 1)],
            $plan,
        );

        config([
            'horizon.defaults' => $plan,
            'horizon.environments' => array_map(
                fn (): array => $sizes,
                (array) config('horizon.environments', []) + ['production' => []],
            ),
        ]);
    }
}
