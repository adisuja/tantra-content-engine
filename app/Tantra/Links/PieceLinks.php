<?php

declare(strict_types=1);

namespace App\Tantra\Links;

use App\Models\Post;
use App\Tantra\Ideas\IdeaMeta;
use App\Tantra\Review\Models\ReviewItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * tantra: puts a tracked CRM short link in place of every untracked link in a
 * piece, for that piece and person, one link per placement (card 29 rule).
 * The copy around each link stays word for word. Every change is appended to
 * storage/app/tantra/link-rewrites.csv.
 */
class PieceLinks
{
    public const LOG = 'tantra/link-rewrites.csv';

    private const MESSAGE_PLACEMENTS = [
        'linkedin_dm' => 'dm', 'connection_note' => 'note', 'email_body' => 'email', 'email_subject' => 'email-subject',
        'whatsapp' => 'wa', 'sms' => 'sms',
    ];

    /** @var list<array<string, string>> */
    public array $changes = [];

    /** @var list<string> */
    public array $errors = [];

    public function __construct(private readonly CrmTracking $crm) {}

    public static function enabled(): bool
    {
        return (bool) config('tantra.crm.links.enabled') && CrmTracking::configured();
    }

    /** Whether any text holds a link we would rewrite (cheap, no I/O beyond one lookup per organic link). */
    public static function hasUntracked(string ...$texts): bool
    {
        foreach ($texts as $text) {
            foreach (LinkRules::urls($text) as $url) {
                if (LinkRules::needsTracking($url, self::ourFullLink(...))) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function ourFullLink(?string $utmContent): bool
    {
        return filled($utmContent) && TrackedLink::query()->where('code', $utmContent)->where('shortened', false)->exists();
    }

    /** True on the first pass: make the CRM links, change nothing. */
    private bool $dry = false;

    /**
     * Two passes, so a slow CRM call never overwrites an edit saved meanwhile:
     * the first makes the links on the copy we have, the second re-reads the
     * row under a lock and puts the (now local) links in.
     */
    private function twoPass(callable $apply, callable $fresh): int
    {
        $this->dry = true;
        try {
            $apply($fresh(false));
        } finally {
            $this->dry = false;
        }

        $n = (int) DB::transaction(fn (): int => ($m = $fresh(true)) !== null ? $apply($m) : 0);
        $this->errors = array_values(array_unique($this->errors));

        return $n;
    }

    public function post(Post $post): int
    {
        return $this->twoPass(fn (Post $p): int => $this->applyPost($p), fn (bool $lock): ?Post => $lock ? self::stillOpen(Post::query()->lockForUpdate()->find($post->id)) : $post);
    }

    /** A post published while the CRM answered keeps the link it went out with. */
    private static function stillOpen(?Post $post): ?Post
    {
        return $post !== null && TrackPieceLinks::open($post) ? $post : null;
    }

    public function reviewItem(ReviewItem $item): int
    {
        return $this->twoPass(fn (ReviewItem $i): int => $this->applyReviewItem($i), fn (bool $lock): ?ReviewItem => $lock ? ReviewItem::query()->lockForUpdate()->find($item->id) : $item);
    }

    public function idea(IdeaMeta $meta): int
    {
        return $this->twoPass(fn (IdeaMeta $m): int => $this->applyIdea($m), fn (bool $lock): ?IdeaMeta => $lock ? IdeaMeta::query()->lockForUpdate()->find($meta->idea_id) : $meta);
    }

    /** The body (content) and the comments (meta.thread_replies) of one channel post. */
    private function applyPost(Post $post): int
    {
        $piece = Piece::ofPost($post);
        $before = count($this->changes);
        $content = $this->rewrite((string) $post->content, $piece, 'post', $piece->bodyMedium, 'post', $post->id, 'content', $post->attachesLinkPreview() && blank($post->media) && blank(data_get($post->meta, 'document_title')));

        $meta = (array) ($post->meta ?? []);
        foreach (array_values((array) ($meta['thread_replies'] ?? [])) as $i => $reply) {
            if (is_array($reply) && is_string($reply['text'] ?? null)) {
                $meta['thread_replies'][$i]['text'] = $this->rewrite($reply['text'], $piece, $i === 0 ? 'comment' : 'thread-'.$i, 'comment', 'post', $post->id, "meta.thread_replies.{$i}.text");
            }
        }

        if (! $this->dry && count($this->changes) > $before) {
            $post->forceFill(['content' => $content, 'meta' => $meta])->saveQuietly();
        }

        return count($this->changes) - $before;
    }

    /** The engine payload (text, first comment, thread, follow-up messages) and the owner's edited messages. */
    private function applyReviewItem(ReviewItem $item): int
    {
        $piece = Piece::ofReviewItem($item);
        $before = count($this->changes);
        $payload = (array) ($item->payload ?? []);

        if (is_string($payload['text'] ?? null)) {
            // The engine text becomes the post body; a LinkedIn post keeps its preview card unless the composer drops it.
            $payload['text'] = $this->rewrite($payload['text'], $piece, 'post', $piece->bodyMedium, 'review_item', $item->id, 'payload.text', blank($payload['media'] ?? null) && ! $item->posts()->contains(fn (Post $p): bool => filled($p->media) || filled(data_get($p->meta, 'document_title'))));
        }
        if (is_string($payload['first_comment'] ?? null)) {
            $payload['first_comment'] = $this->rewrite($payload['first_comment'], $piece, 'comment', 'comment', 'review_item', $item->id, 'payload.first_comment');
        }
        foreach (array_values((array) ($payload['thread'] ?? [])) as $i => $entry) {
            if (is_string($entry)) {
                $payload['thread'][$i] = $this->rewrite($entry, $piece, 'thread-'.($i + 1), 'comment', 'review_item', $item->id, "payload.thread.{$i}");
            } elseif (is_array($entry) && is_string($entry['text'] ?? null)) {
                $payload['thread'][$i]['text'] = $this->rewrite($entry['text'], $piece, 'thread-'.($i + 1), 'comment', 'review_item', $item->id, "payload.thread.{$i}.text");
            }
        }
        foreach ((array) ($payload['messages'] ?? []) as $channel => $text) {
            if (is_string($text)) {
                $payload['messages'][$channel] = $this->rewrite($text, $piece, $this->messagePlacement((string) $channel), 'giveaway', 'review_item', $item->id, "payload.messages.{$channel}");
            }
        }
        $overrides = (array) ($item->message_overrides ?? []);
        foreach ($overrides as $channel => $text) {
            if (is_string($text)) {
                $overrides[$channel] = $this->rewrite($text, $piece, $this->messagePlacement((string) $channel), 'giveaway', 'review_item', $item->id, "message_overrides.{$channel}");
            }
        }

        if (! $this->dry && count($this->changes) > $before) {
            $item->forceFill(['payload' => $payload, 'message_overrides' => $overrides === [] ? $item->message_overrides : $overrides])->saveQuietly();
        }

        return count($this->changes) - $before;
    }

    /** An idea's preview: text, first comment, thread. */
    private function applyIdea(IdeaMeta $meta): int
    {
        $piece = Piece::ofIdea($meta);
        $before = count($this->changes);
        $preview = (array) ($meta->preview ?? []);

        if (is_string($preview['text'] ?? null)) {
            $preview['text'] = $this->rewrite($preview['text'], $piece, 'post', $piece->bodyMedium, 'idea', $meta->idea_id, 'preview.text', blank($preview['media'] ?? null) && blank($meta->upload_tokens));
        }
        if (is_string($preview['first_comment'] ?? null)) {
            $preview['first_comment'] = $this->rewrite($preview['first_comment'], $piece, 'comment', 'comment', 'idea', $meta->idea_id, 'preview.first_comment');
        }
        foreach (array_values((array) ($preview['thread'] ?? [])) as $i => $entry) {
            if (is_string($entry)) {
                $preview['thread'][$i] = $this->rewrite($entry, $piece, 'thread-'.($i + 1), 'comment', 'idea', $meta->idea_id, "preview.thread.{$i}");
            }
        }

        if (! $this->dry && count($this->changes) > $before) {
            $meta->forceFill(['preview' => $preview])->saveQuietly();
        }

        return count($this->changes) - $before;
    }

    /**
     * Replaces each untracked link in one text. Several different links in one
     * placement get one link each (the second is <placement>-2). Only whole
     * links change; a change is recorded only when the text really changed.
     */
    public function rewrite(string $text, Piece $piece, string $placement, string $medium, string $subjectType, string $subjectId, string $field, bool $previewCard = false): string
    {
        $urls = LinkRules::urls($text);
        $firstUrl = $urls[0] ?? null;
        $claimed = [];
        foreach (array_unique($urls) as $url) {
            if (! LinkRules::needsTracking($url, self::ourFullLink(...))) {
                continue;
            }
            $destination = LinkRules::destination($url);
            $where = $claimed[$destination] ??= $this->placementFor($piece, $placement, $destination, $claimed);
            $keepFull = LinkRules::keepsFullLink($piece->platform, $placement, $previewCard, $url === $firstUrl, $destination);

            try {
                $link = $this->link($piece, $where, $medium, $destination, $keepFull);
            } catch (Throwable $e) {
                $this->errors[] = "{$subjectType} {$subjectId} {$field}: ".$e->getMessage();
                Log::warning('tantra: could not track a link', ['subject' => $subjectType, 'id' => $subjectId, 'field' => $field, 'error' => $e->getMessage()]);

                continue;
            }

            $new = $link->url();
            if ($new === '' || $new === $url) {
                continue;
            }
            $replaced = LinkRules::replace($text, $url, $new);
            if ($replaced === $text) {
                continue;
            }
            $text = $replaced;
            if (! $this->dry) {
                $this->record($subjectType, $subjectId, $field, $piece, $link, $url, $new);
            }
        }

        return $text;
    }

    /** The piece's link for this placement: the one made before, else a new CRM short link. */
    public function link(Piece $piece, string $placement, string $medium, string $destination, bool $keepFull): TrackedLink
    {
        $existing = TrackedLink::query()->where([
            'member_key' => $piece->memberKey, 'piece_ref' => $piece->ref, 'platform' => $piece->platform, 'placement' => $placement,
        ])->first();
        if ($existing !== null && $existing->destination === $destination) {
            return $existing;
        }

        $channelId = $this->crm->channel($piece->platform);
        $assetId = $existing?->crm_asset_id ?? TrackedLink::query()
            ->where(['member_key' => $piece->memberKey, 'piece_ref' => $piece->ref, 'platform' => $piece->platform])
            ->whereNotNull('crm_asset_id')->value('crm_asset_id')
            ?? $this->crm->asset($piece->memberKey.': '.$piece->name.' ('.$piece->platform.')', $piece->ref.':'.$piece->platform);

        $created = null;
        $code = '';
        for ($n = 1; $n <= 9 && $created === null; $n++) {
            $code = LinkRules::code($piece->memberKey, $piece->name, $piece->platform, $placement, $medium, $n);
            if (TrackedLink::query()->where('code', $code)->exists()) {
                continue;
            }
            $created = $this->crm->createLink($destination, $channelId, $assetId, $code, $placement, LinkRules::utm($piece->platform, $medium, $code));
        }
        if ($created === null) {
            throw new \RuntimeException("No free code for {$piece->memberKey} {$piece->name} {$placement}.");
        }

        $code = $created['code'] ?: $code;
        $utm = LinkRules::utm($piece->platform, $medium, $code);
        $attributes = [
            'workspace_id' => $piece->workspaceId, 'piece_name' => mb_substr($piece->name, 0, 200), 'medium' => $medium,
            'code' => $code, 'destination' => $destination, 'short_url' => $created['short_url'],
            // The full link always carries our UTMs, so the preview card link and the short link report the same way.
            'long_url' => LinkRules::fullLink($destination, $utm),
            'shortened' => ! $keepFull, 'crm_link_id' => $created['id'], 'crm_asset_id' => $assetId, 'crm_channel_id' => $channelId,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        try {
            return TrackedLink::query()->create([
                'member_key' => $piece->memberKey, 'piece_ref' => $piece->ref, 'platform' => $piece->platform, 'placement' => $placement, ...$attributes,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another worker made it first: use theirs.
            return TrackedLink::query()->where([
                'member_key' => $piece->memberKey, 'piece_ref' => $piece->ref, 'platform' => $piece->platform, 'placement' => $placement,
            ])->firstOrFail();
        }
    }

    /**
     * The placement for a destination in one field: the one it already has for
     * this piece, else the first free one (<placement>, <placement>-2, ...), so
     * adding a link never takes over another link's row.
     *
     * @param  array<string, string>  $claimed
     */
    private function placementFor(Piece $piece, string $placement, string $destination, array $claimed): string
    {
        $rows = TrackedLink::query()->where(['member_key' => $piece->memberKey, 'piece_ref' => $piece->ref, 'platform' => $piece->platform])
            ->where(fn ($q) => $q->where('placement', $placement)->orWhere('placement', 'like', $placement.'-%'))
            ->pluck('destination', 'placement');
        $mine = $rows->search($destination, true);
        if (is_string($mine) && ($mine === $placement || preg_match('/^'.preg_quote($placement, '/').'-\d+$/', $mine))) {
            return $mine;
        }
        for ($k = 1; ; $k++) {
            $candidate = $k === 1 ? $placement : $placement.'-'.$k;
            if (! $rows->has($candidate) && ! in_array($candidate, $claimed, true)) {
                return $candidate;
            }
        }
    }

    private function messagePlacement(string $channel): string
    {
        return self::MESSAGE_PLACEMENTS[$channel] ?? substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($channel)) ?? 'msg', 0, 12);
    }

    private function record(string $subjectType, string $subjectId, string $field, Piece $piece, TrackedLink $link, string $old, string $new): void
    {
        $row = [
            'changed_at' => now()->toIso8601String(),
            'subject_type' => $subjectType,
            'piece_id' => $subjectId,
            'member' => $piece->memberKey,
            'piece' => $piece->name,
            'platform' => $piece->platform,
            'placement' => $link->placement,
            'field' => $field,
            'old_link' => $old,
            'new_link' => $new,
            'code' => $link->code,
            'crm_link_id' => (string) $link->crm_link_id,
        ];
        $this->changes[] = $row;

        $disk = Storage::disk('local');
        $line = self::csvLine(array_values($row));
        if (! $disk->exists(self::LOG)) {
            $disk->put(self::LOG, self::csvLine(array_keys($row)).$line);
        } else {
            $disk->append(self::LOG, rtrim($line, "\n"));
        }
    }

    /** @param list<string> $cells */
    private static function csvLine(array $cells): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $cells, escape: '');
        rewind($h);
        $line = (string) stream_get_contents($h);
        fclose($h);

        return $line;
    }
}
