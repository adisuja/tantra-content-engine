<?php

declare(strict_types=1);

namespace App\Tantra\Notify;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * tantra: every notification to an app user goes through here (Adi, 2026-11-14).
 * With the CRM connected, the event goes to the CRM's automation, which sends
 * the email through Resend and adds Slack, WhatsApp, SMS or an AI call as its
 * sequences say. Without it, or when the CRM does not take it after retries,
 * the Engine sends its own email as before, so nothing is lost.
 */
final class Notify
{
    public static function crmConfigured(): bool
    {
        return filled(config('tantra.crm.intake_key')) && filled(config('tantra.crm.url'));
    }

    /**
     * @param  array<string, mixed>  $data  the event's own fields (count, due_at, channel, url...)
     * @param  Mailable|null  $mail  the email the Engine sends itself when the CRM is not there
     */
    public static function send(User $to, string $event, array $data = [], ?Mailable $mail = null, ?string $workspace = null, ?string $ctaUrl = null, ?string $ctaLabel = null, bool $now = false): void
    {
        if (! self::crmConfigured()) {
            if ($mail !== null) {
                $now ? Mail::to($to)->send($mail) : Mail::to($to)->queue($mail);
            }

            return;
        }

        SendToCrm::dispatch((string) Str::uuid(), "engine.{$event}", $to->id, $data, $mail, $workspace, $ctaUrl, $ctaLabel);
    }
}
