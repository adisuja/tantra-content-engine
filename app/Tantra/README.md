# The Tantra layer

Everything Tantra adds to TryPost lives here, in `database/migrations/tantra/`, `resources/js/pages/tantra/`, `resources/js/components/tantra/`, `lang/*/tantra.php` and `tests/Feature/Tantra/`. The design is the PRD in `adisuja/tantra-content` at `docs/designs/tantra-content-engine.md`. `TantraServiceProvider` wires it all in: routes, commands, schedules, listeners and shared props.

## Switches

| Config | Default | What it turns on |
| --- | --- | --- |
| `tantra.boost.enabled` | off | Team comments, reactions and the author's replies from teammates' own Unipile accounts when a post goes live, and AI threads for composer posts. Waits for Spike S6. |
| `tantra.autoreply.enabled` | off | The hourly templated reply to every comment on a giveaway post, and the DMs to send list. |
| `tantra.health.token` | unset | `GET /tantra/health` for a pinger. Unset, it answers 404. |

## Schedules

| Command | When | What |
| --- | --- | --- |
| `tantra:heartbeat` | every minute | the scheduler tick the health report checks |
| `tantra:heartbeat --media` | every 15 minutes | files missing from storage for posts due in a day |
| `tantra:manual-tasks` | every 5 minutes | X posts and personal polls: due, emailed, expired |
| `tantra:giveaway-replies` | hourly | giveaway auto replies (off by default) |
| `tantra:collect-metrics` | 06:00 and 18:00 UTC | post and page numbers |
| `tantra:reconnect-reminders` | 03:41 UTC | LinkedIn connections about to run out or dropped |
| `tantra:check-connectors` | hourly | tests every Connectors tab entry and records which ones fail and why |

One-off commands: `tantra:ai-connector` (add a connector, key on STDIN), `tantra:create-owner`, `tantra:map-profile`, `tantra:invite`, `tantra:zernio-connect`, `tantra:team-accounts`, `tantra:health`.

## Connectors tab

`/settings/workspace/connectors` (`app/Tantra/Ai`, `ConnectorController`, `pages/tantra/connectors`). One row per Account and provider in `tantra_ai_connectors`, key encrypted with the app key. Every AI call goes through `TantraAi::prompt()`: the default connector first, then the other working AI connectors; each failure is classified (`AiFailure`: out of credits, rate limited, key rejected, model not available, signed out, unreachable) and shown where the AI was used, on the card and in the app banner (`tantraAlerts`). With no connector it runs on the `.env` provider as upstream did. Claude and ChatGPT plans run through the official Claude Code and Codex CLIs installed in the image (`CliTextGateway`).

## Upstream files we touch

Each change is small and marked `// tantra:` (or `<!-- tantra: -->`). After merging upstream TryPost, check each one still applies.

- Platforms: `app/Enums/SocialAccount/Platform.php`, `app/Enums/PostPlatform/ContentType.php`, `app/Jobs/PublishToSocialPlatform.php`, `app/Support/ThreadReplies.php`, `app/Services/Media/MediaOptimizer.php`, `app/Services/Social/ContentSanitizer.php`, `app/Services/Social/ConnectionVerifier.php`, `app/Http/Controllers/Auth/SocialConnectionController.php`, `app/Models/SocialAccount.php`, and on the front end `types/platform.ts`, `types/content-type.ts`, `types/network-options.ts`, `PlatformBrandIcon.vue`, `usePlatformLogo.ts`, `PlatformPreview.vue`, `lib/docs.ts`.
- Approval by the review owner (PRD 10.8): `app/Models/Traits/HasWorkspace.php`, `app/Models/Workspace.php` (approvers), `composables/useWorkspaceAbilities.ts`.
- Slots held by polls posted by hand: `app/Actions/Post/Queue/ListFreeQueueSlots.php`, `ReflowChannelQueue.php`, `ListTakenSlots.php` (a UNION, so it stays one query), `BuildQueueTimeline.php`, and the calendar strip in `BuildCalendarPageProps.php` and `pages/posts/Calendar.vue`.
- Review chips on post cards: `app/Http/Resources/App/PostCardResource.php`, `PostTimelineCard.vue`, `PostDetailsDialog.vue`, `types/publish.ts`.
- Navigation and branding: `AppSidebar.vue`, `AppLogo.vue`, `WorkspaceMenuContent.vue`, `auth/LegalLinks.vue`, `layouts/auth/AuthSplitLayout.vue`.
- MCP: the Tantra tools on `app/Mcp/Servers/TryPostServer.php`.
- AI through the Connectors tab: `app/Actions/Idea/GenerateIdea.php`, `app/Actions/Ai/AssistPostContent.php`, `app/Actions/Ai/GenerateMediaAltText.php`, the error paths of `IdeaGenerateController`, `PostAiAssistantController`, `MediaAltTextController`, the error line in `GenerateIdeasDialog.vue`, `WritingAssistantPanel.vue`, `MediaEditorAltTextPanel.vue`, the banner in `AppSidebarLayout.vue`, the nav item in `useSettingsNavigation.ts`, the CLIs in `docker/Dockerfile`, and the `connectors` label in `lang/*/settings.php` (16 locales, no marker).
- Branding: `app/Services/Http/SafeHttpFetcher.php` (our user agent).
- Tooling: `.prettierignore` (the studio CSS, generated in tantra-content).
- Copy without a marker: the Zernio entries in `lang/*/posts.php` (16 locales).
- Tests that pin upstream messages: the failing-provider cases in `tests/Feature/Create/GenerateIdeaTest.php`, `tests/Browser/CreateIdeaEditorTest.php`, `tests/Feature/Ai/MediaAltTextTest.php`, `tests/Feature/Ai/PostAiAssistantTest.php`.
- Tests that pin upstream lists: `tests/Unit/Enums/PlatformTest.php`, `tests/Browser/SidebarMenuTest.php`, `tests/Feature/Mcp/ToolAnnotationsTest.php`, `tests/Feature/Parity/McpAuthorizationParityTest.php`.

## Operations

Runbooks, the deploy task and the backup pinger are in `adisuja/tantra-ops` (`runbooks/`, `tasks/`, `.github/workflows/health.yml`).
