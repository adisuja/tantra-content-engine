<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use App\Models\User;
use App\Tantra\Mail\Kit;
use App\Tantra\Mail\Letter;
use App\Tantra\Notify\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * tantra: every Content OS billing email. Kinds: welcome (with the receipt),
 * receipt, renewal_notice, payment_failed (steps 0, 1, 2: day 0, 3 and 7),
 * paused, cancelled, expired, winback. Each links a signed page that opens
 * Dodo's portal (update the card) or a new checkout, so no sign in is needed.
 */
class BillingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $context */
    public function __construct(public string $kind, public string $workspaceId, public array $context = []) {}

    /** Emails the workspace owner (and the billing email Dodo has, when it is someone else). */
    public static function deliver(WorkspacePlan $plan, string $kind, array $context): void
    {
        $plan->loadMissing('workspace');
        $owner = User::query()->find($plan->workspace?->user_id);
        if ($owner !== null) {
            Notify::send($owner, 'billing_'.$kind, ['workspace' => $plan->workspace?->name, 'kind' => $kind], new self($kind, $plan->workspace_id, $context), $plan->workspace?->name);
        }
        if (filled($plan->billing_email) && strcasecmp((string) $plan->billing_email, (string) $owner?->email) !== 0) {
            Mail::to($plan->billing_email)->queue(new self($kind, $plan->workspace_id, $context));
        }
    }

    public function envelope(): Envelope
    {
        $plan = $this->plan();
        $step = (int) ($this->context['step'] ?? 0);

        return new Envelope(subject: match ($this->kind) {
            'welcome' => 'Welcome to Content OS: your receipt and the first three steps',
            'receipt' => ! empty($this->context['recovered']) ? 'Payment received: Content OS is back on' : 'Receipt: Content OS for '.$this->month(),
            'renewal_notice' => 'Content OS renews on '.$this->day($plan->next_billing_at),
            'payment_failed' => match ($step) {
                0 => 'Your Content OS payment did not go through',
                1 => 'Reminder: update your card to keep Content OS publishing',
                default => 'Last reminder: publishing pauses on '.$this->day($plan->grace_ends_at),
            },
            'paused' => 'Publishing is paused for '.$plan->workspace?->name,
            'cancelled' => 'Content OS is cancelled. It runs until '.$this->day($plan->period_ends_at),
            'expired' => 'Your Content OS plan has ended',
            'winback' => 'Your posts are still here, ready to go out',
            default => 'Content OS billing',
        });
    }

    public function content(): Content
    {
        $plan = $this->plan();
        $ws = (string) ($plan->workspace?->name ?? 'your workspace');
        $amount = (string) ($this->context['amount'] ?? config('tantra.billing.price_label'));
        $manage = URL::temporarySignedRoute('tantra.billing.manage', now()->addDays(30), ['workspace' => $this->workspaceId]);
        $restart = URL::temporarySignedRoute('tantra.billing.restart', now()->addDays(60), ['workspace' => $this->workspaceId]);

        $letter = match ($this->kind) {
            'welcome' => Letter::make('Content OS is on for '.$ws)
                ->hero(Kit::asset('tantra/email/content-os-hero.gif'), 'One idea goes in, agents turn it into posts for every channel, and views and pipeline come back')
                ->eyebrow('Welcome', 'good')
                ->lead('You bring the ideas. The agents turn each one into posts for every channel, you approve them in a couple of minutes, and the Engine publishes and tracks what comes back.')
                ->steps(['Connect the channels you post on.', 'Send in one idea: a note, a call recording or a voice memo.', 'Approve the drafts when the review email lands.'])
                ->facts([['Paid today', $amount], ['Plan', 'Content OS, monthly'], ['Next charge', $this->day($plan->next_billing_at)], ['Workspace', $ws]])
                ->cta('Open Content OS', Kit::appUrl('/tantra/review'))
                ->secondary('Invoices and billing', $manage)
                ->note('This is your receipt. Dodo Payments processes the charge and sends a tax invoice as well.'),
            'receipt' => Letter::make(! empty($this->context['recovered']) ? 'Payment received. Publishing is back on' : 'Thanks, your Content OS payment went through')
                ->eyebrow('Receipt', 'good')
                ->lead(! empty($this->context['recovered']) ? 'Your card worked this time, so scheduled posts go out as planned again.' : 'Nothing to do. Your posts keep going out on schedule.')
                ->facts([['Paid', $amount], ['Plan', 'Content OS, monthly'], ['Next charge', $this->day($plan->next_billing_at)], ['Workspace', $ws]])
                ->cta('See invoices', $manage),
            'renewal_notice' => Letter::make('Content OS renews in 3 days')
                ->eyebrow('Heads up')
                ->lead("On {$this->day($plan->next_billing_at)} we charge ".config('tantra.billing.price_label')." for {$ws}. If your card changed, update it now so publishing never stops.")
                ->facts([['Charge date', $this->day($plan->next_billing_at)], ['Amount', (string) config('tantra.billing.price_label')]])
                ->cta('Check my card', $manage)
                ->note('No action needed if your card is current.'),
            'payment_failed' => $this->failedLetter($plan, $ws, $manage, (int) ($this->context['step'] ?? 0)),
            'paused' => Letter::make('Publishing is paused for '.$ws)
                ->eyebrow('Paused', 'bad')
                ->lead('We could not collect the Content OS payment, so scheduled posts are on hold. Nothing is lost: posts, drafts, channels and numbers all stay.')
                ->para('Update your card and publishing restarts within minutes. Posts whose time has passed wait for you to pick a new slot.')
                ->cta('Update card and restart', $manage),
            'cancelled' => Letter::make('Content OS is cancelled')
                ->eyebrow('Cancelled')
                ->lead("Posts keep going out until {$this->day($plan->period_ends_at)}. After that publishing stops, and your posts, drafts and numbers stay in {$ws}.")
                ->para('Changed your mind? Turning it back on keeps everything as it is.')
                ->cta('Keep Content OS', $manage),
            'expired' => Letter::make('Your Content OS plan has ended')
                ->eyebrow('Plan ended')
                ->lead("Publishing has stopped for {$ws}. Every post, draft and number is still there, waiting.")
                ->cta('Restart Content OS', $restart)
                ->note('Restarting picks up where you left off. Nothing needs setting up again.'),
            'winback' => Letter::make('Your next posts are one click away')
                ->hero(Kit::asset('tantra/email/content-os-hero.gif'), 'One idea goes in, agents turn it into posts for every channel, and views and pipeline come back')
                ->eyebrow('Still here')
                ->lead("Everything in {$ws} is where you left it: your channels, drafts and the numbers from posts that already went out. Restart and the next idea becomes a week of posts again.")
                ->cta('Restart Content OS', $restart)
                ->note('Content OS is '.config('tantra.billing.price_label').'. Cancel any time from the billing page.'),
            default => Letter::make('Content OS billing')->cta('Open billing', $manage),
        };

        return $letter->reason("You get this because you own {$ws} on Tantra.")->content();
    }

    private function failedLetter(WorkspacePlan $plan, string $ws, string $manage, int $step): Letter
    {
        $pause = $this->day($plan->grace_ends_at);

        return Letter::make(match ($step) {
            0 => 'Your payment did not go through',
            1 => 'Your card still needs updating',
            default => "Publishing pauses on {$pause}",
        })
            ->eyebrow($step === 2 ? 'Last reminder' : 'Payment problem', $step === 2 ? 'bad' : 'warn')
            ->lead(match ($step) {
                0 => "We tried to charge your card for Content OS in {$ws} and the bank declined it. Posts keep going out for now.",
                1 => "The Content OS payment for {$ws} is still unpaid. Posts keep going out until {$pause}.",
                default => "If the payment is still unpaid on {$pause}, scheduled posts for {$ws} stop going out. Your posts and data stay.",
            })
            ->facts([['Amount', (string) ($this->context['amount'] ?? config('tantra.billing.price_label'))], ['Publishing pauses', $pause]])
            ->cta('Update my card', $manage)
            ->note('Updating the card retries the payment straight away.');
    }

    private function plan(): WorkspacePlan
    {
        return WorkspacePlan::query()->with('workspace')->where('workspace_id', $this->workspaceId)->first() ?? new WorkspacePlan(['workspace_id' => $this->workspaceId]);
    }

    private function day(mixed $at): string
    {
        return $at ? Carbon::parse($at)->format('j M Y') : 'the next billing date';
    }

    private function month(): string
    {
        return now()->format('F Y');
    }
}
