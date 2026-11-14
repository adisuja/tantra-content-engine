# Handoff: Content OS emails, digest, billing and CI (2026-11-14)

Branches: `claude/content-os-emails` in tantra-content-engine, tantra-crm and tantra-ops. Nothing is merged or deployed. The Dodo keys are on the Engine server (see section 4).

## 1. CI failures

| Repo, run | Root cause | Fix | Commit |
|---|---|---|---|
| crm `tantra-drift.yml` on every branch (951bc74, fea2c35 and every push since 13 Nov) | The workflow file was invalid YAML: `${{ }}` inside a `{ }` flow mapping (line 17). GitHub then logs a failed, job-less run on **every push to every branch**. That was the email flood. | Rewritten in block YAML. The weekly check now skips with a warning when the `TANTRA_READ_TOKEN` secret is missing (it is missing today). | crm `480d65d` |
| engine Tests, main d673bb2 and e740dcc, r/api 107abae: `CalendarDragAndDropTest` "a post that becomes due..." | **App race.** When the server refused a move fast, Inertia sometimes repainted the optimistic move after rolling it back, so the card stayed in a cell the post was never in. Reproduced locally about 1 run in 6. | `onError` now reloads the calendar's posts after the rollback. 0 failures in 15 runs after the fix. | engine `734cd4af` |
| engine Tests, main 4e4c312 and e740dcc: "the card sits in its new cell..." and "an older move answered after..." | **Test timing.** The test read the card's cell the instant the request was held, before Vue had painted. | The tests wait for the card to be in the asserted cell. The older-move test also records every cell the card is painted in and fails if it ever flickers back. Nothing was loosened. | engine `734cd4af` |
| engine r/api 7fb9b0a, r/insights 0169f60, r/templates 775ca9d, r/slots 32a7632, PR "Review: a link per post..." (3cdda5b) | Real failures on those branches: AgentJobs email collision, LinkedIn analytics on connect, `RedirectResponse::session()`, API owner check, locale key drift. | Each was fixed by a later commit on its own branch (028cf8cf, 273c72aa, 77819cb4 and the PR's follow-ups). Every one of these branches is **fully merged into main** (0 commits ahead). | n/a |

**Stale branches to close.** These are fully merged into main, so delete them: engine `r/api`, `r/insights`, `r/insights-all`, `r/merge-insights`, `r/templates`, `r/slots`, `r/agent-jobs`, `r/ideas`, `r/rebrand`, `r/repurpose`, `r/connect-seamless`, `r/crm-notify`, `claude/determined-thompson-vcpbbd`. Two CRM branches are **live work, not stale**: `claude/dreamy-archimedes-cr0bx5` (Pages spec, 7 ahead) and `claude/busy-galileo-wnmqor` (2 PRDs). They need no fix of their own; they go green once they rebase onto main after the CRM PR merges.

**Quieter alerts (proposal, not applied).** Main stays loud. (1) Remove `'r/**'` from the engine Tests `push` trigger: work branches get CI through their PR, and pushes to throwaway branches stop emailing. (2) Delete the 13 merged branches above. (3) In GitHub notification settings, set Actions to "Only notify for failed workflows on the default branch".

Ops: `deploy.sh` no longer fails a healthy deploy when two `docker image prune` runs collide. I reviewed it; it is correct. It is cherry-picked into the ops PR (`381abea`).

## 2. Every email

The trigger file is where it is dispatched. Path: `Notify` means the CRM's Resend automation runs first and the Engine's own mail is the fallback; `Mail` means the Engine mailer directly, which in production is SMTP over Resend from `engine@notify.usetantra.com`. Before and after images for each email are in `docs/emails/before-after/<email>-600.png` and `<email>-375.png`. Each image shows four columns: before light, before dark, after light, after dark.

| Email | Trigger | To | Path | New subject |
|---|---|---|---|---|
| review-ready | review import (`NotifyReviewers`) | review owner | Notify | 4 posts ready for review, week of 16 Nov |
| numbers-to-sign-off | same import | account owner | Notify | 2 numbers to sign off in Tantra's posts |
| manual-task-due | `tantra:manual-tasks`, every 5 min | task owner | Notify | Post your LinkedIn poll now |
| media-missing | `tantra:heartbeat --media`, every 15 min | review owner | Notify | Fix before Sat 14 Nov, 13:12 UTC: a post is missing its file |
| reconnect-channel | `tantra:reconnect-reminders`, 03:41 | review owner | Notify | Your LinkedIn connection runs out in 5 days |
| connect-access-requested | pre-connect request | channel apps manager | Notify | Kavya Rao is waiting on you to connect Instagram |
| teammate-welcome | `tantra:invite-teammate` | invitee | Notify | Your LinkedIn posts are ready for you to review |
| account-disconnected | channel drops (`SocialAccount`) | owner | Mail | Posting to LinkedIn has stopped: reconnect Adi Murali |
| workspace-connections-disconnected | daily connection check | owner | Mail | 1 channel in Tantra needs reconnecting |
| post-at-risk | 15 min upcoming check | owner | Mail | 1 scheduled post in Tantra will not go out unless you reconnect |
| post-note-added | note on a post | members | Mail | Kavya Rao left a note on your post |
| post-approval-requested | sent for approval | approvers | Mail | Kavya Rao needs your approval on a post |
| post-approved / post-rejected | approval decision | requester | Mail | Approved: your post is scheduled / Adi Murali sent your post back with changes |
| post-published / post-publish-failed | `FinalizePostPublication` | members | Mail | Live on LinkedIn: your post just went out / Your LinkedIn post did not go out |
| webhook-paused | 5th failed delivery | webhook owner | Mail | Webhook paused after 5 failed deliveries |
| workspace-invite | invite created | invitee | Mail | Adi Murali invited you to Tantra |
| email-verification / password-reset | Laravel auth (unused while login is Clerk) | user | Mail | Confirm your email for Tantra / Reset your Tantra password |
| **daily-digest** (new) | `tantra:daily-digest`, hourly, sends at 08:00 local | each member with something to report | Notify | Your day on Tantra: 4 posts to review, 3 went live, +2,240 views |
| **billing-*** (10, new) | Dodo webhooks and `tantra:billing-tick` (hourly) | workspace owner and Dodo billing email | Notify | see `docs/emails/rendered/index.json` |

The three CRM-only events (rebrand_ready, template_ready, agent_job_waiting) have no Engine email; the CRM sends them.

**Before:** the 12 upstream emails still said "TryPost" (logo, button "Open in TryPost", footer) and had no dark mode. The Tantra emails were a plain list of codes and dates.

**After:** one layout for all 31 emails. Each says what happened, why it matters and the one action to take. The largest HTML is 31.9 KB (Gmail clips at 102 KB). `MailGalleryTest` fails the build if any email goes over 102 KB, contains an em or en dash, says TryPost, prints a model as JSON, or leaves the shared layout.

Review cards show the account and avatar, the slot in the reviewer's timezone, a "New version" badge, the copy clamped with "Read more", the first comment, and the media: image, carousel (cover, strip of slides, count), animated GIF, or video (poster with a play button that opens the post).

**No Approve link.** Approving needs a signed-in session, and the app has no signed, expiring approve token. An approve link in an inbox would let anyone holding the email approve, so I left it out.

Inbox test: all 31 emails were sent through the Engine's mailer (tantra-ops `tasks/mail-gallery-send.sh`) to adi@growthclub.org and boards@fintechcircle.com. All landed in the inbox. Gmail filed 7 under Promotions (published, failed, poll, approval, cancelled, renewal heads-up, at-risk); see section 6.

## 3. Daily digest

The digest shows: posts waiting (3 cards, then "and N more"), posts published in the last 24 hours and all time, posts scheduled for the next 7 days, views across every channel with the change since yesterday, and views per network.

- **Views** are each post's latest views count (impressions, exposure or reach where the network has no views), summed.
- **Standout number**, first that has real data: (1) human clicks from tracked links, counted by the CRM with bots removed; (2) views on the best post of the last 30 days; (3) posts published.
- **Hours saved is not used.** No minutes-per-post is measured anywhere, so it would be an invented number. The same goes for the agency cost.

Members can switch it off in Settings, Notifications (all 16 languages), or with a signed one-click unsubscribe (`List-Unsubscribe-Post`).

## 4. Billing: Content OS on Dodo

- **Product:** `pdt_0Nrun85o9IVj9jlux7Nsf`, "Content OS", $199 a month, recurring (live mode). No existing product matched. A live checkout was created and checked: it shows Content OS and localises to INR for an Indian visitor.
- **Webhook URL to register in Dodo:** `https://content.usetantra.com/tantra/billing/dodo/webhook`. It goes live after the Engine PR is deployed. Events: subscription.active, renewed, on_hold, past_due, failed, cancelled, expired, updated, plan_changed; payment.succeeded, payment.failed.
- **Env var names on the server** (`/opt/tantra/.env`): `DODO_PAYMENTS_API_KEY`, `DODO_WEBHOOK_SECRET` (both via sync-env, stored as tantra-ops secrets) and `DODO_ENV=live_mode` (via env-set). Optional: `DODO_CONTENT_OS_PRODUCT_ID` (defaults to the id above), `TANTRA_BILLING_ENFORCE` (default false), `TANTRA_BILLING_GRACE_DAYS` (default 10).
- **Plan state** is stored per workspace in `tantra_workspace_plans`. The **grace period is 10 days**, with reminders on day 0, 3 and 7. **What a lapsed plan locks:** publishing only. Scheduled posts stay scheduled, and every post, draft, channel and number stays. Locking only happens once you set `TANTRA_BILLING_ENFORCE=true`.
- **Testing:** the whole cycle (welcome, receipt, heads-up, dunning, pause, recovery, cancel, expiry, win-back, other products ignored, duplicates, forged, stale and unsigned requests) is covered in `ContentOsBillingTest` with real Standard Webhooks signatures. I did **not** run it against Dodo test mode: there is no test-mode key. Only the two live keys exist.

## 5. Kie.ai spend

- 1 image, seedream 4.5 (backdrop and idea orb): **6.5 credits, about $0.03**, on the `kie-2` key (197.88 credits before, 191.38 after).
- The diagram, motion and labels are drawn in `docs/emails/hero/compose.py`. Output: `public/tantra/email/content-os-hero.gif` (600x300, 868 KB) and `content-os-hero.png` (56 KB fallback).

## 6. Open items and what I need from you

1. **Dodo webhook:** register the URL above in Dodo (Developer, Webhooks, Add endpoint) after deploy. Then copy that endpoint's signing secret into the tantra-ops secret `DODO_WEBHOOK_SECRET` and run "Sync secrets to server". Dodo gives each endpoint its own secret; the one in `dodo.env` belongs to an existing endpoint.
2. **Dodo test mode:** if you want the cycle run against Dodo itself first, I need a test-mode API key.
3. **Deploy go-ahead:** merge order is ops, CRM, then Engine (migrations run on deploy). Billing enforcement stays off until you set it.
4. **Promotions tab:** 7 of the test emails went to Promotions. Fixes on your side: a DMARC policy for notify.usetantra.com, and replies going to a monitored address.
5. **Win-back legal footer:** the win-back email is promotional. Give me a postal address for its footer (CAN-SPAM) before it is switched on.
6. **Not done:** a Vue billing page inside the app. `/tantra/billing` is a plain server-rendered page with Start and Manage buttons.

**Decisions I made for you**
- Used `dodo.env`, not `dodo-enterprise.env`; it is the account with every Tantra product.
- Sent tests to adi@growthclub.org and to boards@fintechcircle.com, because the Gmail connector can only read the latter.
- Billing is per workspace, not per account, as you asked.
- Grace period is 10 days, so the day-7 reminder comes before the pause.
- Tantra copy is English only. Upstream's translated emails return if branding is turned off.
