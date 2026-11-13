# The Tantra layer

Everything Tantra adds to TryPost lives here, in `database/migrations/tantra/`, `resources/js/pages/tantra/`, `resources/js/components/tantra/`, `lang/*/tantra.php` and `tests/Feature/Tantra/`. The design is the PRD in `adisuja/tantra-content` at `docs/designs/tantra-content-engine.md`. `TantraServiceProvider` wires it all in: routes, commands, schedules, listeners and shared props.

## Switches

| Config | Default | What it turns on |
| --- | --- | --- |
| `tantra.boost.enabled` | off | Team comments, reactions and the author's replies from teammates' own Unipile accounts when a post goes live, and AI threads for composer posts. Waits for Spike S6. |
| `tantra.autoreply.enabled` | off | The hourly templated reply to every comment on a giveaway post, and the DMs to send list. |
| `tantra.health.token` | unset | `GET /tantra/health` for a pinger. Unset, it answers 404. |
| `tantra.clerk.enabled` (`TANTRA_CLERK_LOGIN`) | off | Sign in with the usetantra.com login (Clerk). See "Sign in with Clerk" below. |

## Sign in with Clerk

`app/Tantra/Auth`, `ClerkSessionController`, `pages/tantra/auth/ClerkSignIn.vue`. With `TANTRA_CLERK_LOGIN=true`, `/login` shows Clerk's sign in from usetantra.com's instance (`clerk.usetantra.com`), laid out like usetantra.com/sign-in. A session already started on usetantra.com carries over (same root domain), so most people go straight in. The page posts Clerk's session token to `POST /auth/clerk/session`; `ClerkToken` checks it against the instance's public JWKS (RS256, exp, nbf, `iss` = the Frontend API, `azp` in `CLERK_AUTHORIZED_PARTIES`) and `ClerkLogin` finds the Engine user by `clerk_user_id`, else by verified email (from the token, or from Clerk's Backend API with `CLERK_SECRET_KEY`). Unknown emails see "Ask your admin for access". Team access comes only from the Clerk-verified email, never from the email stored on an Engine user. A link to a Clerk user that no longer exists is replaced by the next Clerk user with the same verified email; `tantra:clerk-unlink {email}` or the Team page clears a link by hand. The team logins in `TANTRA_TEAM_EMAILS` (default `team@growthclubpro.com`) are made an admin of every workspace at each sign in (`TeamAccess`, also `php artisan tantra:team-account`). The password form stays at `/login?password=1` ("Other sign in options"). Signing out of the Engine also ends the Clerk session.

Env: `TANTRA_CLERK_LOGIN`, `CLERK_SECRET_KEY`, and only to override the defaults `CLERK_PUBLISHABLE_KEY`, `CLERK_FRONTEND_API`, `CLERK_AUTHORIZED_PARTIES`, `TANTRA_TEAM_EMAILS`.

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
| `tantra:linkedin-sync` | every 15 minutes | matches new Unipile LinkedIn accounts to teammates by name, reads each person's LinkedIn identity once a day, and the page's from Zernio |

One-off commands: `tantra:ai-connector` (add a connector, key on STDIN), `tantra:create-owner`, `tantra:team-account`, `tantra:clerk-unlink`, `tantra:map-profile`, `tantra:invite`, `tantra:zernio-connect`, `tantra:team-accounts`, `tantra:health`.

## Review screen

`/tantra/review` (`ReviewPageController::index`, `pages/tantra/review/Index.vue`, `ReviewCard.vue`) is the one review screen and where people land: `LandOnReview` sends the first calendar visit of a session in a Tantra workspace there. Three tabs: Review, Scheduled and Published. Each post is its phone preview from `platforms.ts` (LinkedIn, the LinkedIn page, X, and a plain fallback for networks without their own look yet), with Approve and schedule, feedback for the next engine round, and Undo once scheduled.

## Ideas with a network and a preview

`app/Tantra/Ideas`, `components/tantra/ideas` (PRD 25.8). A Tantra idea is an upstream idea plus one `tantra_idea_meta` row: the network (`linkedin`, `linkedin_page`, `x`, `facebook`, `instagram`, `youtube`, `threads`, `tiktok`, `pinterest`, `bluesky`, `mastodon`), an optional channel, format, template, hook, a preview (`text`, `title`, `first_comment`, `thread[]`, `media {type, files[], video, poster, tall}`, files as https links or `tantra/previews/` paths), held `upload_tokens`, the kind (`new`, `repurpose`, `repost`), its source review item or post, cadence week, target date, `agent_job_id` (no foreign key until the jobs table lands), and the state (`idea`, `in_review`, `dropped`).

- API, owner key only: `POST api/tantra/ideas` (bulk upsert by `external_key`, unique per Account; the workspace by `profile` or `workspace_id`; a new idea goes to the workspace's first stage, or to the stage named in `stage`, made once), `GET api/tantra/ideas` (`workspace_id`, `profile`, `kind`, `state`, `platform`, `batch` for the "Batch N" stage, `stage`, `external_key`, `since`), `PATCH api/tantra/ideas/{idea}`, `POST api/tantra/ideas/{idea}/to-review`, `DELETE api/tantra/ideas/{idea}`. An upsert only changes ideas still in `idea`; a dropped idea or one in review comes back as `skipped` with the reason. A PATCH can set a dropped idea back to `idea`.
- Send to review (`IdeaToReview`, also on the card and `POST tantra/ideas/{idea}/to-review`): goes through `ImportBatch` as engine batch `ideas-<Ymd>` for the workspace's review owner, with a draft on the idea's channel (its `channel_id`, else the workspace's one channel for that network; several need a `channel_id`) in that network's post type (`Networks::contentType`). No channel for the network: 422 "Connect Facebook to publish first." X is posted by hand (PRD 12): an assisted manual item whose task is due at its planned time. The planned time is a hint from the channel's posting times on the target date, skipping times other open items hold; approval takes the real slot. The idea and the review item point at each other (`review_item_id`, `tantra_review_items.idea_id`); sending again returns the same item.
- MCP: `tantra-list-ideas-tool`, `tantra-upsert-ideas-tool`, `tantra-idea-to-review-tool`. The Account owner works across the Account's workspaces, anyone else in their current workspace.
- Board: a Tantra idea's card shows the network, kind and week badges, a small phone (the review registry in `platforms.ts`, now with Facebook, Instagram, YouTube, Threads and TikTok screens in `studioRender.ts` and `css/studio-networks.css`), Preview at full size, Send to review, and "Connect <Network> to publish" (the Add channel dialog) while the network has no channel. A strip above the board counts the ideas ready per network. A workspace with no Tantra idea gets the upstream board unchanged.

## Identities come from LinkedIn

Nobody's name, headline or photo is typed in. `app/Tantra/LinkedIn`:

- `LinkedInSync` matches each LinkedIn account in Tantra's Unipile to the Engine user with the same name (one to one, never confirmed on its own: Adi confirms on the Team page before anything is posted in someone's name). It then reads `users/me` through that account: name, headline (`description`), member id, profile URL, followers and the large photo. The photo is copied to `tantra/linkedin/{user}.jpg`, since LinkedIn's links expire, and becomes the person's Engine photo unless they uploaded their own. A 401 marks the account disconnected. The Tantra page gets its name, logo and followers from Zernio.
- `SyncLinkedInIdentity` reads a person at once when Adi matches their account on the Team page.
- `Identity` is what the previews show: the page channel for page posts, the person's LinkedIn identity, else their LinkedIn channel, else their Engine name; the X channel for X. A `profile_card` from an older batch only fills gaps.

## Connectors tab

`/settings/workspace/connectors` (`app/Tantra/Ai`, `ConnectorController`, `pages/tantra/connectors`). One row per Account and provider in `tantra_ai_connectors`, key encrypted with the app key. Every AI call goes through `TantraAi::prompt()`: the default connector first, then the other working AI connectors; each failure is classified (`AiFailure`: out of credits, rate limited, key rejected, model not available, signed out, unreachable) and shown where the AI was used, on the card and in the app banner (`tantraAlerts`). With no connector it runs on the `.env` provider as upstream did. Claude and ChatGPT plans run through the official Claude Code and Codex CLIs installed in the image (`CliTextGateway`).

## Upstream files we touch

Each change is small and marked `// tantra:` (or `<!-- tantra: -->`). After merging upstream TryPost, check each one still applies.

- Platforms: `app/Enums/SocialAccount/Platform.php`, `app/Enums/PostPlatform/ContentType.php`, `app/Jobs/PublishToSocialPlatform.php`, `app/Support/ThreadReplies.php`, `app/Services/Media/MediaOptimizer.php`, `app/Services/Social/ContentSanitizer.php`, `app/Services/Social/ConnectionVerifier.php`, `app/Http/Controllers/Auth/SocialConnectionController.php`, `app/Models/SocialAccount.php`, and on the front end `types/platform.ts`, `types/content-type.ts`, `types/network-options.ts`, `PlatformBrandIcon.vue`, `usePlatformLogo.ts`, `PlatformPreview.vue`, `lib/docs.ts`.
- Approval by the review owner (PRD 10.8): `app/Models/Traits/HasWorkspace.php`, `app/Models/Workspace.php` (approvers), `composables/useWorkspaceAbilities.ts`.
- Slots held by polls posted by hand: `app/Actions/Post/Queue/ListFreeQueueSlots.php`, `ReflowChannelQueue.php`, `ListTakenSlots.php` (a UNION, so it stays one query), `BuildQueueTimeline.php`, and the calendar strip in `BuildCalendarPageProps.php` and `pages/posts/Calendar.vue`.
- Review chips on post cards: `app/Http/Resources/App/PostCardResource.php`, `PostTimelineCard.vue`, `PostDetailsDialog.vue`, `types/publish.ts`.
- Sign in with Clerk: `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (the login page and sign out), `NewPasswordController.php` (back to the password form), `app/Http/Requests/App/Settings/ProfileUpdateRequest.php` (emails lowercase and unique in any case).
- Navigation and branding: `AppSidebar.vue`, `AppLogo.vue`, `WorkspaceMenuContent.vue`, `auth/LegalLinks.vue`, `layouts/auth/AuthSplitLayout.vue`.
- MCP: the Tantra tools on `app/Mcp/Servers/TryPostServer.php`.
- Ideas (PRD 25.8): `app/Actions/Idea/BuildIdeasPageProps.php` (the `tantraIdeas` prop), `app/Actions/Idea/ListIdeas.php` (loads the meta), `app/Http/Resources/App/IdeaCardResource.php` (the card's `tantra`), and on the front end `pages/create/Ideas.vue` (the strip and the provider), `components/create/ideas/IdeaCard.vue` (the panel), `types/idea.ts`. The `tantraMeta` relation is added from `TantraServiceProvider`, not in `Idea.php`.
- AI through the Connectors tab: `app/Actions/Idea/GenerateIdea.php`, `app/Actions/Ai/AssistPostContent.php`, `app/Actions/Ai/GenerateMediaAltText.php`, the error paths of `IdeaGenerateController`, `PostAiAssistantController`, `MediaAltTextController`, the error line in `GenerateIdeasDialog.vue`, `WritingAssistantPanel.vue`, `MediaEditorAltTextPanel.vue`, the banner in `AppSidebarLayout.vue`, the nav item in `useSettingsNavigation.ts`, the CLIs in `docker/Dockerfile`, and the `connectors` label in `lang/*/settings.php` (16 locales, no marker).
- Branding: `app/Services/Http/SafeHttpFetcher.php` (our user agent).
- Tooling: `.prettierignore` (the studio CSS, generated in tantra-content).
- Copy without a marker: the Zernio entries in `lang/*/posts.php` (16 locales).
- Tests that pin upstream messages: the failing-provider cases in `tests/Feature/Create/GenerateIdeaTest.php`, `tests/Browser/CreateIdeaEditorTest.php`, `tests/Feature/Ai/MediaAltTextTest.php`, `tests/Feature/Ai/PostAiAssistantTest.php`.
- Tests that pin upstream lists: `tests/Unit/Enums/PlatformTest.php`, `tests/Browser/SidebarMenuTest.php`, `tests/Feature/Mcp/ToolAnnotationsTest.php`, `tests/Feature/Parity/McpAuthorizationParityTest.php`.

## Operations

Runbooks, the deploy task and the backup pinger are in `adisuja/tantra-ops` (`runbooks/`, `tasks/`, `.github/workflows/health.yml`).
