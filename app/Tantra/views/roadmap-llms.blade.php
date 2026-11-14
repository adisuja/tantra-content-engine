# Tantra product roadmap

> The public roadmap of Tantra's products ({{ implode(', ', array_values($products)) }}). Anyone can read it and ask for a feature. Requests land in the first column, "Q: Requests". Only the Tantra team moves or edits cards.

Human page: {{ $base }}/roadmap

## Columns, left to right

@foreach ($columns as $column)
- `{{ $column->value }}`: {{ $column->label() }}. {{ $column->blurb() }}
@endforeach

## Products

@foreach ($products as $key => $label)
- `{{ $key }}`: {{ $label }}
@endforeach

## Read (no key)

- `GET {{ $base }}/api/roadmap` returns the board as JSON. Filters: `?product=content-engine`, `?column=in_progress`.
- `GET {{ $base }}/roadmap.json` is the same JSON as a static style file.
- `GET {{ $base }}/roadmap/feed` is an Atom feed of new and shipped cards.

Each item: `id`, `title`, `summary`, `detail`, `column`, `column_label`, `product`, `category`, `position`, `source` (`team` or `request`), `requested_by` (first name only), `created_at`, `updated_at`, `shipped_at`. Emails are never public.

## Ask for a feature (no key)

`POST {{ $base }}/api/roadmap/requests` with JSON:

```json
{"title": "What should Tantra do for you", "description": "Why it matters, what you do today", "product": "content-engine", "name": "Your name", "email": "you@company.com"}
```

`title` is required (3 to 120 characters). `description` up to 2000 characters. `name` and `email` are optional; the email stays private and is used only to tell you when it ships. Plain text only. Five requests per IP per hour. Answers `201` with the new card.

## Change the board (API key)

Send `Authorization: Bearer <key>`. The key must be the instance owner's API key or a personal key with the `roadmap:write` scope (the owner issues one with `php artisan tantra:roadmap-key`).

- `POST {{ $base }}/api/roadmap/items` with `title`, `description`, `column`, `product`, `category`: add a card.
- `PATCH {{ $base }}/api/roadmap/items/{id}` with any of `title`, `description`, `column`, `position`, `product`, `category`: edit it.
- `POST {{ $base }}/api/roadmap/items/{id}/move` with `column` and an optional `position` (1 is the top): move it.
- `DELETE {{ $base }}/api/roadmap/items/{id}`: remove it.

## MCP

The Content Engine's MCP server at `{{ $base }}/mcp/trypost` (OAuth) has `roadmap_list`, `roadmap_add_request`, `roadmap_move` and `roadmap_update`. Moving and editing need the instance owner.

## Webhooks

In Settings > Webhooks, subscribe to `roadmap.request_created` (someone asked for a feature) and `roadmap.item_moved` (a card changed column). Payloads carry the public card. Signed with `X-Webhook-Signature` (HMAC SHA256 of the JSON body).
