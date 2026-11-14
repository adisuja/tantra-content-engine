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
| `tantra:collect-metrics` | 06:00 and 18:00 UTC | post and page numbers for `/tantra/insights` and the batch results |
| upstream `analytics:*` | upstream's own times | followers, post history and post numbers for the Insights tab, LinkedIn included (see "Insights") |
| `tantra:reconnect-reminders` | 03:41 UTC | LinkedIn connections about to run out or dropped |
| `tantra:check-connectors` | hourly | tests every Connectors tab entry and records which ones fail and why |
| `tantra:agent-jobs` | every minute | agent jobs: re-fire ones no run picked up, requeue a run that went quiet, purge inputs 30 days after a job ends |
| `tantra:repurpose-cadence` | Mondays 04:21 UTC | each published post moves to its next network (repurpose jobs, results in Ideas) |
| `tantra:linkedin-sync` | every 15 minutes | matches new Unipile LinkedIn accounts to teammates by name, reads each person's LinkedIn identity once a day, and the page's from Zernio |

One-off commands: `tantra:ai-connector` (add a connector, key on STDIN), `tantra:create-owner`, `tantra:team-account`, `tantra:clerk-unlink`, `tantra:map-profile`, `tantra:invite`, `tantra:zernio-connect`, `tantra:team-accounts`, `tantra:health`.

## Review screen

`/tantra/review` (`ReviewPageController::index`, `pages/tantra/review/Index.vue`, `ReviewCard.vue`) is the one review screen and where people land: `LandOnReview` sends the first calendar visit of a session in a Tantra workspace there. Three tabs: Review, Scheduled and Published. Each post is its phone preview from `platforms.ts` (LinkedIn, the LinkedIn page, X, and a plain fallback for networks without their own look yet), with Approve and schedule, feedback for the next engine round, and Undo once scheduled.

Each post has its own link, `/tantra/review?item=<id>` (`ReviewItem::shareUrl()`, the "Copy link to this post" button), which survives the sign in redirect and opens that post on whichever tab holds it. The card has three columns: the phone preview, the asset (the resource's pages from `media_manifest.resource_previews`, else the post's own slides, with the public `/previewlink/{token}` page from `PreviewLinkController`, no sign in, a 40 character random token) and the controls. Under the asset sit the follow-up messages (`App\Tantra\Review\Messages`: LinkedIn DM, connection note, email subject and body, WhatsApp, SMS): pushed as `messages` by the content kit, copied with one click, edited by the review owner (`message_overrides`, kept through re-pushes, `POST /tantra/review/items/{item}/messages/{channel}`). Follow-ups read the edited text: the published post's DM list, and `GET /api/tantra/messages/{batch}/{post}?first_name=` for the CRM and agents.
## Channels and posting times

PRD 25.6. `app/Tantra/Channels/ChannelSchedules.php`, `TeamController::schedule`, `Api/ChannelScheduleController`, `components/tantra/team/ChannelScheduleCard.vue`. The Team page has a "Channels and posting times" section: every channel in the workspaces a person reviews (the Account owner sees everyone's), each with its network icon, timezone and the upstream weekly grid, so a personal profile, a page and an X account each get their own times. Saves go to `PUT /tantra/team/channels/{channel}/schedule`, allowed for that workspace's review owner and the Account owner, validated with upstream's `UpdatePostingScheduleRequest` and saved through upstream's `UpdatePostingSchedule`, which reflows the channel's queue. A goal or schedule left out of the request stays as it is. The same is on the API (`GET/PUT api/tantra/channels/{channel}/schedule`, owner token) and MCP (`tantra-get-channel-schedule-tool`, `tantra-update-channel-schedule-tool`).

Unipile and Zernio channels arrive with default times nobody chose, so `meta.schedule_confirmed_at` is set the first time a person saves a channel's times (the Team page, the API, MCP, the channel settings page or the posting goal step after connecting). Until then the Team page asks "Check the times we set for ...", and the checklist's "posting times set" step needs every connected channel they review to have at least one enabled time and checked times.

Onboarding: one connection can create several channels (a LinkedIn profile and its pages). `SocialController` flashes them all as `connectedChannels`, and the connect dialog opens the posting goal step for each in turn.

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

## Insights

The upstream Insights tab and each channel's Insights page show every LinkedIn channel (PRD 25.7). `Platform::isIncludedInAnalytics()` is on for LinkedIn, the Zernio page and Unipile LinkedIn (the LinkedIn page app stays off), and the three upstream collector factories hand them to `app/Tantra/Analytics/Collectors`. These call our clients, never the OAuth refresh path, and fail with upstream's `AnalyticsCollectionException`; a missing account is a permission problem, never a deleted post.

- Unipile LinkedIn reads through the channel's own account (`platform_user_id`). OAuth LinkedIn has no analytics scope, so it reads through the workspace review owner's confirmed Unipile account (`MemberProfile`); without one it says "no Unipile account matched for this LinkedIn profile".
- Followers: the identity sync's count when a day old at most, else `users/me`, cached 6 hours (`users/me` is rate limited). Posts: `users/me/posts`, each page cached for an hour and shared by discovery and every post's read, so one account costs one list call an hour. Reposts of other people's posts are left out. Numbers: impressions (`analytics.impressions_counter`, missing stays missing), reactions of every kind summed, comments, reposts (stored as shares, as upstream does for X).
- The Tantra page: followers from Zernio's account list, posts from Zernio's published LinkedIn posts, numbers from Zernio's post analytics by URN (impressions, reach, reactions, comments, reposts, clicks). A 402 pauses Zernio analytics for a day, shared with `tantra:collect-metrics`.
- Each post is stored under the id our publishers keep in `posts.platform_post_id` (Unipile's id, Zernio's post id, or the OAuth share URN, matched to Unipile's id once and remembered), so the Engine's own posts link to their numbers. Network posts of these channels are never added to the calendar: upstream's external import only links them to Engine posts.
- `tantra:collect-metrics` still feeds `/tantra/insights` and the batch results; it reads a Unipile channel through its own account too.

## Studio ops and agent jobs

`/tantra/ops` (`OpsController`, `pages/tantra/ops/Index.vue`) and `api/tantra/ops/*`, for `TANTRA_ADMIN_EMAILS` with a verified email only (`App\Tantra\Agent\Admin`, `EnsureTantraAdmin`); everyone else gets a 404 and no sidebar entry. PRD 25.4 and 25.5.

- `tantra_agent_jobs`: one piece of work for our Claude Code. Jobs an admin starts are queued and fire the first enabled cloud task (`CloudTask`, the scheduled task's API trigger); jobs anyone else starts wait as `awaiting_admin` until an admin presses Run, because a Claude subscription is its owner's own.
- Runners (`tantra_agent_runners`) take jobs over `api/tantra/agent/*` with their own token: `claim` (atomic, optionally one `job_id`), then `GET`, `log`, `complete`, `fail` with the `X-Claim-Token` from that claim. A run that lost its claim gets 409.
- The Engine applies results (`Appliers`, one `JobApplier` per kind, idempotent); the agent never writes to the Engine directly. Features register their appliers in `TantraServiceProvider`.
- The worker side is in tantra-content `kits/agent/` (`job.py`, `runner.py`, `cloud-task.md`, `prompts/`).

## Content templates

`/tantra/templates` (`TemplatesController`, `pages/tantra/templates/Index.vue`), the fourth Create tab. `tantra_content_templates` holds one row per sample (seeded from tantra-content with `POST api/tantra/templates`, owner token, upsert by key, plus `POST api/tantra/templates/{key}/preview`) and one per post someone added (`POST /tantra/templates/intake` or `api/tantra/templates/intake`): `TemplateIntake` makes it `building`, reads a LinkedIn post itself through Unipile, and queues a `template_from_post` agent job; `TemplateFromPostApplier` makes it live and keeps its preview under `tantra/templates/<key>/`. `GET api/tantra/templates?origin=intake&since=&full=1` is how the content repo pulls new samples. PRD 25.9.

## Repost and the repurposing cadence

PRD 25.10. `Repurposing` is the fifth Create tab (`RepurposingController`, `pages/tantra/repurposing/Index.vue`): the cadence (`tantra_repurpose_cadences`, default LinkedIn week 0, Facebook 1, Instagram 2, YouTube 3, X 4, Threads 5, TikTok 6), each network's preset (`tantra_repurpose_presets`, pushed from tantra-content `knowledge/content/repurpose/` with `POST api/tantra/repurposing/presets`) and the Account's own rules on top (`tantra_repurpose_rules`).

- **Repost** (Review's Scheduled and Published cards, `RepostDialog.vue`; `POST /tantra/repost` and `api/tantra/repost`): pick channels across the workspaces you use, the same channel again (a rework), or networks not connected yet. `Reposts::start` builds the job input in the Engine and queues one `repost` agent job.
- `RepostApplier` (also for `repurpose`) makes one idea per version (external key `job-<id>-<n>`, so applying twice changes nothing); a Repost to a connected channel goes straight to Review through `IdeaToReview`.
- `tantra:repurpose-cadence` (Mondays 04:21 UTC, `WeeklyCadence`) queues the next network for every original published post whose week has come; `tantra_repurpose_progress` (unique per post and network) keeps a post from reaching a network twice. The first job of a run fires a cloud run, which claims the rest.

## Profile rebrands

PRD 25.11. On the Team page (`RebrandPanel.vue`, `RebrandController`): the person, the Account owner or an admin asks for one; `Rebrands::start` reads the profile through the person's own Unipile account and queues a `rebrand` agent job; `RebrandApplier` stores the proposal (headline options, About, banner, featured). Only the person applies the headline (`PATCH users/me` through `UnipileClient::updateProfile`, then read back to check it took); the rest is copied by hand.

## API for everything

PRD 25.12. Every screen has an API under `api/tantra/*` (reference: tantra-content `knowledge/content/engine-api.md`, made by `kits/engine/routes_doc.py`; one CLI over it: `kits/engine/engine.py`). The read side of the screens (`TantraReadController`: review items, posts by hand, team, Insights) and the screens' own action controllers answer as the token's own user, with the same rights. Owner-only stays for cross-workspace writes (batches, bulk ideas, the template seed); ops needs an admin; runners use their own token.

## Connectors tab

`/settings/workspace/connectors` (`app/Tantra/Ai`, `ConnectorController`, `pages/tantra/connectors`). One row per Account and provider in `tantra_ai_connectors`, key encrypted with the app key. Every AI call goes through `TantraAi::prompt()`: the default connector first, then the other working AI connectors; each failure is classified (`AiFailure`: out of credits, rate limited, key rejected, model not available, signed out, unreachable) and shown where the AI was used, on the card and in the app banner (`tantraAlerts`). With no connector it runs on the `.env` provider as upstream did. Claude and ChatGPT plans run through the official Claude Code and Codex CLIs installed in the image (`CliTextGateway`).

## Upstream files we touch

Each change is small and marked `// tantra:` (or `<!-- tantra: -->`). After merging upstream TryPost, check each one still applies.

- Platforms: `app/Enums/SocialAccount/Platform.php`, `app/Enums/PostPlatform/ContentType.php`, `app/Jobs/PublishToSocialPlatform.php`, `app/Support/ThreadReplies.php`, `app/Services/Media/MediaOptimizer.php`, `app/Services/Social/ContentSanitizer.php`, `app/Services/Social/ConnectionVerifier.php`, `app/Http/Controllers/Auth/SocialConnectionController.php`, `app/Models/SocialAccount.php`, and on the front end `types/platform.ts`, `types/content-type.ts`, `types/network-options.ts`, `PlatformBrandIcon.vue`, `usePlatformLogo.ts`, `PlatformPreview.vue`, `lib/docs.ts`.
- Approval by the review owner (PRD 10.8): `app/Models/Traits/HasWorkspace.php`, `app/Models/Workspace.php` (approvers), `composables/useWorkspaceAbilities.ts`.
- Slots held by polls posted by hand: `app/Actions/Post/Queue/ListFreeQueueSlots.php`, `ReflowChannelQueue.php`, `ListTakenSlots.php` (a UNION, so it stays one query), `BuildQueueTimeline.php`, and the calendar strip in `BuildCalendarPageProps.php` and `pages/posts/Calendar.vue`.
- Review chips on post cards: `app/Http/Resources/App/PostCardResource.php`, `PostTimelineCard.vue`, `PostDetailsDialog.vue`, `types/publish.ts`.
- Sign in with Clerk: `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (the login page and sign out), `NewPasswordController.php` (back to the password form), `app/Http/Requests/App/Settings/ProfileUpdateRequest.php` (emails lowercase and unique in any case).
- Content templates tab: `components/create/CreateTabs.vue`, `types/idea.ts` (`CreateTab`).
- Navigation and branding: `AppSidebar.vue` (also the Studio ops entry for admins), `AppLogo.vue`, `WorkspaceMenuContent.vue`, `auth/LegalLinks.vue`, `layouts/auth/AuthSplitLayout.vue`.
- MCP: the Tantra tools on `app/Mcp/Servers/TryPostServer.php`.
- Posting times for every new channel: `app/Http/Controllers/Auth/SocialController.php` (flashes `connectedChannels`), `app/Http/Controllers/App/ChannelPostingScheduleController.php` (marks the times checked), `components/channels/ConnectChannelDialog.vue` (the goal step for each new channel).

- Ideas (PRD 25.8): `app/Actions/Idea/BuildIdeasPageProps.php` (the `tantraIdeas` prop), `app/Actions/Idea/ListIdeas.php` (loads the meta), `app/Http/Resources/App/IdeaCardResource.php` (the card's `tantra`), and on the front end `pages/create/Ideas.vue` (the strip and the provider), `components/create/ideas/IdeaCard.vue` (the panel), `types/idea.ts`. The `tantraMeta` relation is added from `TantraServiceProvider`, not in `Idea.php`.
- AI through the Connectors tab: `app/Actions/Idea/GenerateIdea.php`, `app/Actions/Ai/AssistPostContent.php`, `app/Actions/Ai/GenerateMediaAltText.php`, the error paths of `IdeaGenerateController`, `PostAiAssistantController`, `MediaAltTextController`, the error line in `GenerateIdeasDialog.vue`, `WritingAssistantPanel.vue`, `MediaEditorAltTextPanel.vue`, the banner in `AppSidebarLayout.vue`, the nav item in `useSettingsNavigation.ts`, the CLIs in `docker/Dockerfile`, and the `connectors` label in `lang/*/settings.php` (16 locales, no marker).
- Branding: `app/Services/Http/SafeHttpFetcher.php` (our user agent).
- Insights for LinkedIn (PRD 25.7): `Platform::isIncludedInAnalytics()`, the three collector factories in `app/Services/Analytics/Collectors/{Followers,Publications,Metrics}`, the calendar import guard in `app/Actions/Post/ImportExternalPosts.php`, poll and document types in `app/Actions/Analytics/SyncTryPostPublication.php`, and the LinkedIn metric sets in `lib/publicationMetrics.ts`.
- Tooling: `.prettierignore` (the studio CSS, generated in tantra-content).
- Copy without a marker: the Zernio entries in `lang/*/posts.php` (16 locales).
- Tests that pin upstream messages: the failing-provider cases in `tests/Feature/Create/GenerateIdeaTest.php`, `tests/Browser/CreateIdeaEditorTest.php`, `tests/Feature/Ai/MediaAltTextTest.php`, `tests/Feature/Ai/PostAiAssistantTest.php`.
- Tests that pin upstream lists: `tests/Unit/Enums/PlatformTest.php`, `tests/Browser/SidebarMenuTest.php`, `tests/Feature/Mcp/ToolAnnotationsTest.php`, `tests/Feature/Parity/McpAuthorizationParityTest.php`.
- Tests that used LinkedIn as the platform without analytics (now the LinkedIn page app): `tests/Feature/Analytics/{PublicationMetricsJobsTest,WorkspaceAnalyticsReportTest,InsightsControllerTest,AnalyticsSurfaceParityTest,PersistedPostMetricsReadTest,ChannelInsightsPageTest,AccountDailyJobsTest}.php`, `tests/Feature/Analytics/Collectors/FollowerCollectorsTest.php`, `tests/Feature/Observers/{PostPublicationObserverTest,SocialAccountObserverTest}.php`, `tests/Feature/Jobs/PostHog/TrackSocialAccountConnectedTest.php`, and `tests/Feature/Enums/SocialAccount/PlatformConnectableOptionsTest.php` (LinkedIn now shows analytics).

## Operations

Runbooks, the deploy task and the backup pinger are in `adisuja/tantra-ops` (`runbooks/`, `tasks/`, `.github/workflows/health.yml`).
