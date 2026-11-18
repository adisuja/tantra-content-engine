<?php

declare(strict_types=1);

namespace App\Tantra\Create;

use App\Models\User;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Templates\ContentTemplate;
use Illuminate\Support\Str;
use Throwable;

/**
 * tantra: the format cards of Tantra's Content > Create (AF-09, PRD 9.22). One card per live content template the
 * Account can see (the seeded samples and the ones built from the team's own posts), the formats the team has
 * already used first. A card is named by its template key, never by the template's id.
 */
final class FormatCards
{
    /** A template key as a card handle: a seeded sample (LI12) or one built from a post (T-3). */
    public const KEY = '/^[A-Z]{1,4}-?\d{1,4}$/';

    /** @return list<array<string, mixed>> */
    public static function for(User $user): array
    {
        $used = self::usage((string) $user->account_id);
        $templates = ContentTemplate::visibleTo($user->account_id)->where('status', 'live')->orderBy('key')->limit(300)->get();

        return $templates
            ->filter(fn (ContentTemplate $t): bool => preg_match(self::KEY, (string) $t->key) === 1)
            ->map(fn (ContentTemplate $t): array => self::card($t, $used[$t->key] ?? 0))
            ->sortBy(fn (array $c): string => sprintf('%d|%06d|%s', $c['used'] > 0 ? 0 : 1, 999999 - $c['used'], $c['title']))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public static function card(ContentTemplate $t, int $used = 0): array
    {
        return [
            'key' => $t->key,
            'title' => Str::limit((string) $t->title, 120, ''),
            'platform' => $t->platform,
            'format' => $t->format,
            'family' => $t->family,
            'about' => Str::limit(trim((string) ($t->description ?? $t->archetype ?? '')), 280),
            'preview' => $t->previewUrl(),
            'own' => $t->origin === 'intake',
            'used' => $used,
        ];
    }

    /** How often the Account's posts followed each template (the sample on each review item). Never throws. */
    private static function usage(string $accountId): array
    {
        if ($accountId === '') {
            return [];
        }
        try {
            $out = [];
            ReviewItem::query()->where('account_id', $accountId)->whereNotNull('payload')->latest('updated_at')->limit(2000)
                ->get(['payload'])
                ->each(function (ReviewItem $i) use (&$out): void {
                    $key = data_get($i->payload, 'sample.id');
                    if (is_string($key) && $key !== '') {
                        $out[$key] = ($out[$key] ?? 0) + 1;
                    }
                });

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
