<?php

declare(strict_types=1);

namespace App\Tantra\Notify;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * tantra: the one door for the 11 upstream emails the Engine used to mail straight from its own mailer (R3, 15 Nov).
 * While the Engine owns an email (its flag in the main app is "engine", the default, or the main app is not connected)
 * it is sent exactly as before. Once the main app owns it ("app"), the Engine sends only the engine.* event, with the
 * data from CoreEvents and no copy of its own, and the main app sends it on its kit. "off" sends the event too, and the
 * main app records it as skipped. Either way one person gets one email.
 */
final class DirectMail
{
    /**
     * @param  User|string  $to  a user, or an email address (an invite to someone without an account)
     */
    public static function send(User|string $to, Mailable $mail, ?string $locale = null, ?string $workspace = null): void
    {
        $event = CoreEvents::eventFor($mail);
        if ($event === null || ! Notify::crmConfigured() || CrmMailFlags::engineOwns($event)) {
            $pending = Mail::to($to);
            if ($locale !== null) {
                $pending->locale($locale);
            }
            $pending->send($mail);

            return;
        }

        $user = $to instanceof User ? $to : null;
        $data = CoreEvents::dataFor($mail, $user);
        // No Mailable goes with the event: SendToCrm::failed sends nothing for an email the Engine does not own.
        SendToCrm::dispatch((string) Str::uuid(), $event, $user?->id ?? '', $data, null, $workspace ?? ($data['workspace'] ?? null), $data['url'] ?? null, null, $user === null ? (string) $to : null);
    }
}
