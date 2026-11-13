<?php

declare(strict_types=1);

namespace App\Tantra\Roadmap;

use App\Enums\Webhook\EventType;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookService;
use App\Tantra\Channels\ChannelApps;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\AccessToken as PassportAccessToken;

/**
 * tantra: the public product roadmap. Every surface (the page, the request
 * form, the JSON API, MCP and the owner's screen) goes through here, so the
 * rules are the same everywhere: plain text only, no dashes in our own copy,
 * the email never public, and only the instance owner or a roadmap:write key
 * may change a card.
 */
class Roadmap
{
    public const DEFAULT_PRODUCT = 'content-engine';

    public const SCOPE = 'roadmap:write';

    /** @var array<string, string> */
    public const PRODUCTS = [
        'content-engine' => 'Content Engine',
        'tantra-app' => 'Tantra app',
        'crm' => 'Tantra CRM',
    ];

    /** @var list<string> */
    public const CATEGORIES = ['Channels', 'Publishing', 'Engagement', 'Analytics', 'AI', 'Platform'];

    public const TITLE_MAX = 120;

    public const DESCRIPTION_MAX = 2000;

    /** Requests a visitor may send per IP per hour. */
    public const REQUESTS_PER_HOUR = 5;

    /**
     * The instance owner, or a personal API key carrying roadmap:write.
     *
     * A key must be live (Passport's JWT runs for 100 years; the app's own
     * expiry is on the row). The scope only counts on a personal access key
     * the owner issued (tantra:roadmap-key): an OAuth client could ask for it
     * on a consent screen anyone can approve for themselves.
     */
    public static function canWrite(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $token = self::tokenOf($user);
        if ($token === false) {
            return false;
        }

        if (ChannelApps::canManage($user)) {
            return true;
        }

        return $token instanceof AccessToken
            && in_array(self::SCOPE, $token->scopes ?? [], true)
            && $token->isPersonalAccessToken();
    }

    /** Who asked (name and email) is for the instance owner only. */
    public static function canSeeRequesters(?User $user): bool
    {
        return $user !== null && ChannelApps::canManage($user) && self::tokenOf($user) !== false;
    }

    /** @return array<string, mixed> */
    public static function present(RoadmapItem $item, ?User $user): array
    {
        return self::canSeeRequesters($user) ? $item->privateArray() : $item->publicArray();
    }

    /**
     * The API key or OAuth token behind this request: null for a session,
     * false when it is missing, revoked or past its expiry.
     */
    private static function tokenOf(User $user): AccessToken|false|null
    {
        $passport = $user->token();
        if (! $passport instanceof PassportAccessToken) {
            return null;
        }

        $token = AccessToken::query()->find($passport->oauth_access_token_id);

        return $token === null || $token->revoked || $token->expires_at?->isPast() ? false : $token;
    }

    /**
     * The board as anyone may read it.
     *
     * @return array<string, mixed>
     */
    public static function payload(?string $product = null, ?string $column = null): array
    {
        $items = RoadmapItem::query()
            ->when($product !== null, fn ($q) => $q->where('product', $product))
            ->when($column !== null, fn ($q) => $q->where('column', $column))
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->get();

        return [
            'name' => 'Tantra product roadmap',
            'url' => url('/roadmap'),
            'product' => $product,
            'columns' => collect(Column::cases())
                ->when($column !== null, fn ($c) => $c->filter(fn (Column $col): bool => $col->value === $column))
                ->map(fn (Column $col): array => [
                    'key' => $col->value,
                    'label' => $col->label(),
                    'blurb' => $col->blurb(),
                    'count' => $items->where('column', $col)->count(),
                ])->values()->all(),
            'products' => collect(self::PRODUCTS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'categories' => $items->pluck('category')->filter()->unique()->sort()->values()->all(),
            'items' => $items->map(fn (RoadmapItem $item): array => $item->publicArray())->values()->all(),
            'generated_at' => now()->toIso8601ZuluString(),
        ];
    }

    /**
     * A feature request from the form, the API or an agent. Lands in Q.
     *
     * @param  array{title: string, description?: string|null, product?: string|null, name?: string|null, email?: string|null}  $data
     */
    public static function request(array $data, ?string $ip = null): RoadmapItem
    {
        $item = DB::transaction(fn (): RoadmapItem => RoadmapItem::query()->create([
            'title' => self::line($data['title'], self::TITLE_MAX),
            'description' => self::text($data['description'] ?? null),
            'product' => $data['product'] ?? self::DEFAULT_PRODUCT,
            'column' => Column::Q,
            'position' => self::nextPosition(Column::Q, $data['product'] ?? self::DEFAULT_PRODUCT),
            'source' => 'request',
            'requester_name' => filled($data['name'] ?? null) ? self::line((string) $data['name'], 120) : null,
            'requester_email' => filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null,
            'requester_ip_hash' => $ip !== null ? hash('sha256', $ip.'|'.config('app.key')) : null,
        ]));

        self::notify(EventType::RoadmapRequestCreated, ['item' => $item->publicArray()]);

        return $item;
    }

    /**
     * A card the team adds (owner screen, write API).
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data): RoadmapItem
    {
        self::guardCopy($data);
        $column = Column::from((string) ($data['column'] ?? Column::Planned->value));
        $product = (string) ($data['product'] ?? self::DEFAULT_PRODUCT);

        return RoadmapItem::query()->create([
            'title' => self::line((string) $data['title'], self::TITLE_MAX),
            'description' => self::text($data['description'] ?? null),
            'product' => $product,
            'category' => filled($data['category'] ?? null) ? self::line((string) $data['category'], 40) : null,
            'column' => $column,
            'position' => self::nextPosition($column, $product),
            'source' => 'team',
            'shipped_at' => $column === Column::Shipped ? now() : null,
        ]);
    }

    /**
     * Edits the copy and, when given, the column and position.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(RoadmapItem $item, array $data): RoadmapItem
    {
        self::guardCopy($data);
        $fields = [];
        if (array_key_exists('title', $data)) {
            $fields['title'] = self::line((string) $data['title'], self::TITLE_MAX);
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = self::text($data['description']);
        }
        if (array_key_exists('category', $data)) {
            $fields['category'] = filled($data['category']) ? self::line((string) $data['category'], 40) : null;
        }
        if (array_key_exists('product', $data) && filled($data['product'])) {
            $fields['product'] = (string) $data['product'];
        }
        $item->fill($fields)->save();

        if (filled($data['column'] ?? null) || array_key_exists('position', $data) && $data['position'] !== null) {
            self::move($item, Column::from((string) ($data['column'] ?? $item->column->value)), isset($data['position']) ? (int) $data['position'] : null);
        }

        return $item->refresh();
    }

    /** Moves a card to a column, at the end or at a position (1 is the top). */
    public static function move(RoadmapItem $item, Column $to, ?int $position = null): RoadmapItem
    {
        $from = $item->column;

        DB::transaction(function () use ($item, $to, $position): void {
            if ($position === null) {
                $position = $item->column === $to ? $item->position : self::nextPosition($to, $item->product);
            } else {
                $position = max(1, $position);
                RoadmapItem::query()
                    ->where('product', $item->product)
                    ->where('column', $to)
                    ->whereKeyNot($item->id)
                    ->where('position', '>=', $position)
                    ->increment('position');
            }

            $item->forceFill([
                'column' => $to,
                'position' => $position,
                'shipped_at' => $to === Column::Shipped ? ($item->shipped_at ?? now()) : null,
            ])->save();
        });

        if ($from !== $to) {
            self::notify(EventType::RoadmapItemMoved, [
                'item' => $item->refresh()->publicArray(),
                'from' => $from->value,
                'to' => $to->value,
            ]);
        }

        return $item;
    }

    public static function delete(RoadmapItem $item): void
    {
        $item->delete();
    }

    /** One line of plain text: no tags, no line breaks, no long dashes. */
    public static function line(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', self::plain($value))), 0, $max);
    }

    /** Paragraphs of plain text: no tags, at most one blank line in a row. */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = str_replace("\r\n", "\n", self::plain($value));
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace("/ *\n */u", "\n", $text);
        $text = trim((string) preg_replace("/\n{3,}/u", "\n\n", $text));

        return $text === '' ? null : mb_substr($text, 0, self::DESCRIPTION_MAX);
    }

    /**
     * Sends a roadmap event to every enabled webhook on the instance owner's
     * workspaces that asked for it (Settings > Webhooks). Public card only:
     * any workspace admin can add a webhook, and who asked is the owner's.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function notify(EventType $event, array $payload): void
    {
        $accountId = Account::query()->orderBy('created_at')->orderBy('id')->value('id');
        if ($accountId === null) {
            return;
        }

        $service = app(WebhookService::class);
        Webhook::query()
            ->enabled()
            ->whereHas('workspace', fn ($q) => $q->where('account_id', $accountId))
            ->with('workspace')
            ->get()
            ->pluck('workspace')
            ->unique('id')
            ->each(fn ($workspace) => $service->dispatch($workspace, $event, $payload));
    }

    private static function plain(string $value): string
    {
        // Real tags and comments only, so "x<y" or "I <3 this" survive.
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/<!--.*?(-->|$)/su', '', $text);
        $text = (string) preg_replace('#</?[a-zA-Z][^<>]*>#u', '', $text);
        // Long dashes read as machine written; a comma keeps the sentence.
        $text = (string) preg_replace('/\s*[\x{2013}\x{2014}]\s*/u', ', ', $text);

        return (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{FFFE}\x{FFFF}]/u', '', $text);
    }

    /** Team copy must be ours as written: refuse dashes instead of rewriting them. */
    private static function guardCopy(array $data): void
    {
        foreach (['title', 'description', 'category'] as $field) {
            if (is_string($data[$field] ?? null) && preg_match('/[\x{2013}\x{2014}]/u', $data[$field])) {
                throw ValidationException::withMessages([$field => 'No en or em dashes, please.']);
            }
        }
    }

    private static function nextPosition(Column $column, string $product): int
    {
        return (int) RoadmapItem::query()->where('product', $product)->where('column', $column)->max('position') + 1;
    }
}
