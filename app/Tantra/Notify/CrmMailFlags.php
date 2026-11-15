<?php

declare(strict_types=1);

namespace App\Tantra\Notify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * tantra: who sends each email (tantra-crm docs/crm/HANDOFF_REPORT.md, "Cutover"). The main app keeps one flag per
 * email at GET {crm}/intake/engine/mail-flags: "engine" (the Engine renders it, as before), "app" (the main app renders
 * it on its kit) or "off". The Engine never sends its own copy, or its fallback, for an email it does not own, so
 * nobody gets two. Read once a minute; when the main app cannot be reached the last answer it gave is used, and with no
 * answer ever the Engine owns everything (today's behaviour).
 */
final class CrmMailFlags
{
    private const string CACHE = 'tantra:crm-mail-flags';

    private const string LAST = 'tantra:crm-mail-flags:last';

    /** @return array{flags: array<string, string>, events: array<string, string>} */
    public static function all(): array
    {
        if (! Notify::crmConfigured()) {
            return ['flags' => [], 'events' => []];
        }

        return Cache::remember(self::CACHE, 60, function (): array {
            try {
                $response = Http::timeout(5)->acceptJson()->withToken((string) config('tantra.crm.intake_key'))
                    // Tells the main app which emails this Engine can hand over as events (its cutover plan reads it).
                    ->withHeaders(['X-Tantra-Engine-Emits' => implode(',', CoreEvents::EMITS)])
                    ->get(rtrim((string) config('tantra.crm.url'), '/').'/intake/engine/mail-flags');
                if ($response->successful() && is_array($response->json('flags'))) {
                    $answer = ['flags' => (array) $response->json('flags'), 'events' => (array) ($response->json('events') ?? [])];
                    Cache::forever(self::LAST, $answer);

                    return $answer;
                }
            } catch (Throwable) {
                // fall through to the last answer
            }

            return Cache::get(self::LAST, ['flags' => [], 'events' => []]);
        });
    }

    /** The owner of the email an engine.* event carries: "engine", "app" or "off". Several keys can share one event (billing). */
    public static function ownerOfEvent(string $event): string
    {
        $all = self::all();
        $owners = [];
        foreach ($all['events'] as $key => $name) {
            if ($name === $event) {
                $owners[] = (string) ($all['flags'][$key] ?? 'engine');
            }
        }

        return $owners === [] ? 'engine' : (in_array('engine', $owners, true) ? 'engine' : $owners[0]);
    }

    public static function engineOwns(string $event): bool
    {
        return self::ownerOfEvent($event) === 'engine';
    }
}
