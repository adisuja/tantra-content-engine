<?php

declare(strict_types=1);

namespace App\Tantra\Notify;

use App\Models\User;
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
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $response = Http::timeout(20)->acceptJson()->withToken((string) config('tantra.crm.intake_key'))
            ->post(rtrim((string) config('tantra.crm.url'), '/').'/intake/engine', $this->payload($user));

        if (! $response->successful()) {
            throw new RuntimeException("The CRM did not take {$this->event} (HTTP {$response->status()}).");
        }
    }

    public function failed(Throwable $e): void
    {
        $user = User::query()->find($this->userId);
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
                $html = (string) $this->mail->render();
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
                'user_id' => $user->id, 'email' => $user->email, 'name' => $user->name,
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
                'cta_url' => $this->ctaUrl,
            ],
        ];
    }
}
