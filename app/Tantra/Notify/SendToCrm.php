<?php

declare(strict_types=1);

namespace App\Tantra\Notify;

use App\Models\User;
use App\Tantra\Embed\Embed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * tantra: hands one notification event to the CRM (`POST /intake/engine`, the
 * contract in tantra-crm docs/crm/ENGINE_NOTIFICATIONS_SPEC.md). The id makes
 * a retry safe. After the last try the Engine sends its own email instead.
 */
final class SendToCrm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly string $userId,
        public readonly array $data,
        public readonly ?Mailable $mail,
        public readonly ?string $workspace,
        public readonly ?string $ctaUrl,
        public readonly ?string $ctaLabel,
        /** An invite to someone without an account: the address the event is for when there is no user (R3). */
        public readonly ?string $toEmail = null,
    ) {}

    public function handle(): void
    {
        $user = $this->recipient();
        if ($user === null) {
            return;
        }

        $response = Http::timeout(20)->acceptJson()->withToken((string) config('tantra.crm.intake_key'))
            ->post(rtrim((string) config('tantra.crm.url'), '/').'/intake/engine', $this->payload($user));

        if (! $response->successful()) {
            throw new RuntimeException("The CRM did not take {$this->event} (HTTP {$response->status()}).");
        }
    }

    /** The user the event is for, or an unsaved stand in carrying only the address when it was sent to an email (an invite). */
    private function recipient(): ?User
    {
        if ($this->userId !== '') {
            return User::query()->find($this->userId);
        }
        if (blank($this->toEmail)) {
            return null;
        }
        $user = new User;
        $user->forceFill(['email' => (string) $this->toEmail, 'name' => null]);
        $user->id = null;

        return $user;
    }

    public function failed(Throwable $e): void
    {
        // The main app owns this email (or it is off): the Engine's fallback must not send a second copy.
        if (! CrmMailFlags::engineOwns($this->event)) {
            return;
        }
        $user = $this->recipient();
        if ($user !== null && $this->mail !== null) {
            Mail::to($user)->send($this->mail);
        }
    }

    /** @return array<string, mixed> */
    public function payload(User $user): array
    {
        $subject = null;
        $html = null;
        if ($this->mail !== null) {
            try {
                $subject = $this->mail->envelope()->subject;
            } catch (Throwable) {
                $subject = $this->mail->subject;
            }
            try {
                // Links open inside Tantra when TANTRA_EMBED_EMAIL_LINKS is on (S-12).
                $html = Embed::shellLinks((string) $this->mail->render());
            } catch (Throwable) {
                $html = null;
            }
        }
        $subject ??= (string) ($this->data['subject'] ?? str($this->event)->after('engine.')->replace('_', ' ')->ucfirst());

        return [
            'id' => $this->id,
            'event' => $this->event,
            'occurred_at' => now()->toIso8601String(),
            'recipient' => [
                // An invite to an address without an account has no user id: the address stands in for it.
                'user_id' => $user->id ?? 'email:'.strtolower((string) $user->email), 'email' => $user->email, 'name' => $user->name,
                // Phone numbers live in the CRM contact until the Engine has its own field.
                'phone' => $user->getAttributes()['phone'] ?? null, 'whatsapp' => $user->getAttributes()['whatsapp'] ?? null,
                'timezone' => $user->getAttributes()['timezone'] ?? null,
            ],
            'workspace' => $this->workspace,
            'data' => $this->data,
            'message' => [
                'subject' => $subject,
                'text' => (string) ($this->data['text'] ?? ($html !== null ? trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '') : $subject)),
                'html' => $html,
                'cta_label' => $this->ctaLabel,
                'cta_url' => Embed::shellLink($this->ctaUrl),
            ],
        ];
    }
}
