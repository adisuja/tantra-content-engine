# Tantra product roadmap

The public roadmap at `/roadmap`: a read only Kanban of what we are building, with a request form under it. Today it covers the Content Engine; `product` already allows `tantra-app` and `crm`, so the same board can move to usetantra.com and into the Tantra app.

## Columns

| Key | Label | Meaning |
| --- | --- | --- |
| `q` | Q: Requests | New requests from the form, the API and agents |
| `planned` | Planned | We are doing these next |
| `in_progress` | In progress | Being built right now |
| `beta` | Beta | Live, still being polished |
| `shipped` | Shipped | Done; `shipped_at` is stamped when a card moves here |

## Data

Table `tantra_roadmap_items` (`app/Tantra/Roadmap/RoadmapItem.php`), ULID ids. `title`, `description` (the first paragraph is the card line, the rest opens on expand), `column`, `product`, `category`, `position`, `source` (`team` or `request`), `requester_name`, `requester_email`, `requester_ip_hash`, `shipped_at`, timestamps. The migration seeds the work in flight on 14 Nov 2026.

Privacy: the email, the full name and the IP hash never leave the server on a public surface. The page, the JSON, the feed and agents get `requested_by` (first name only). Only the instance owner sees who asked: on the owner screen, through MCP and in write API answers made with the owner's key. Webhooks and `roadmap:write` keys get the public card.

All rules live in `App\Tantra\Roadmap\Roadmap`: plain text only (tags stripped), length limits (title 120, description 2000), and team copy refuses en and em dashes. Requests from the public have long dashes turned into commas.

## Read (no key)

| Route | What |
| --- | --- |
| `GET /roadmap` | The page (Inertia, own root view with title, description, canonical, Open Graph and a plain HTML copy of the board for crawlers) |
| `GET /api/roadmap` | The board as JSON. Filters `?product=content-engine`, `?column=in_progress` |
| `GET /roadmap.json` | The same JSON, with `Access-Control-Allow-Origin: *` |
| `GET /roadmap/feed` | Atom feed of new and shipped cards (latest 50) |
| `GET /roadmap/llms.txt` | Plain text guide for AI agents |

JSON shape: `name`, `url`, `product`, `columns[] {key, label, blurb, count}`, `products[] {key, label}`, `categories[]`, `items[] {id, title, summary, detail, column, column_label, product, product_label, category, position, source, requested_by, created_at, updated_at, shipped_at}`, `generated_at`.

## Ask for a feature (no key)

`POST /api/roadmap/requests`, JSON `{title, description?, product?, name?, email?}`. Answers `201` with `item`; the card is in Q at once. No session and no CSRF, so it works from usetantra.com (CORS covers `api/*`).

Spam protection: a honeypot field `website` (filled means a fake success and nothing stored), 5 requests per IP per hour (`429` with `Retry-After`), length limits, HTML stripped.

## Change the board

Only the instance owner (`ChannelApps::canManage`: the owner of the first Account) or a personal API key with the `roadmap:write` scope. The scope counts only on a personal access key from `tantra:roadmap-key`, never on an OAuth grant (anyone can approve their own consent screen), and an expired or revoked key is refused.

Issue a scoped key for an agent or a teammate: `php artisan tantra:roadmap-key person@company.com --name="Roadmap agent"`. It prints the key once and shows in that person's Settings > API keys, where it can be revoked. The owner's own personal API key works without the scope.

| Route | Body |
| --- | --- |
| `POST /api/roadmap/items` | `title`, `description`, `column`, `product`, `category` |
| `PATCH /api/roadmap/items/{id}` | any of `title`, `description`, `column`, `position`, `product`, `category` |
| `POST /api/roadmap/items/{id}/move` | `column`, optional `position` (1 is the top) |
| `DELETE /api/roadmap/items/{id}` | |

Send `Authorization: Bearer <key>`. Anyone else gets `403`.

## Owner screen

Settings > Roadmap (`/settings/workspace/roadmap`, owner only): every card by column with who asked, a column picker to move it, edit and delete, and Add a card.

## MCP

On the Engine's MCP server (`/mcp/trypost`):

| Tool | Who | What |
| --- | --- | --- |
| `roadmap_list` | anyone signed in | The board; the owner also sees requesters |
| `roadmap_add_request` | anyone signed in | A request in Q under the caller's name and email |
| `roadmap_move` | owner or a `roadmap:write` key | Move a card to a column and position |
| `roadmap_update` | owner or a `roadmap:write` key | Edit title, description, category, product, column, position |

## Webhooks

Settings > Webhooks has a Roadmap group with two events, sent to the instance owner's workspaces only:

- `roadmap.request_created`: `{item}` (the public card).
- `roadmap.item_moved`: `{item, from, to}`.

Payloads carry the public card only, since any workspace admin can add a webhook. The owner reads who asked through `roadmap_list` or Settings > Roadmap.

Signed like every webhook: `X-Webhook-Signature` is the HMAC SHA256 of the JSON body with the webhook's signing secret.

## Moving it to usetantra.com

`resources/js/components/tantra/roadmap/TantraRoadmap.vue` and `RoadmapRequestForm.vue` import nothing from the app (Vue only, scoped CSS, Inter, the usetantra.com palette). Drop them into any Vue 3 site:

```vue
<TantraRoadmap source="https://content.usetantra.com/api/roadmap" />
<RoadmapRequestForm endpoint="https://content.usetantra.com/api/roadmap/requests" />
```

`types.ts` describes the JSON. When the roadmap moves into the Tantra app, the table, `Roadmap` and the routes move with it; the JSON stays the contract.
