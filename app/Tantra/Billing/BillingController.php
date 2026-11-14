<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Tantra\Mail\Kit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** tantra: Content OS billing pages, the checkout and portal redirects, and the Dodo webhook. */
class BillingController extends Controller
{
    /** POST /tantra/billing/dodo/webhook. Verified, stored once by webhook-id, then applied. */
    public function webhook(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $id = (string) $request->header('webhook-id');
        $valid = WebhookSignature::verify((string) config('tantra.billing.webhook_secret'), $id, (string) $request->header('webhook-timestamp'), (string) $request->header('webhook-signature'), $body);
        if (! $valid) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $event = json_decode($body, true);
        if (! is_array($event)) {
            return response()->json(['error' => 'invalid json'], 400);
        }

        // ON CONFLICT DO NOTHING: a retry of an event already stored changes nothing (and aborts no transaction).
        $stored = BillingEvent::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'webhook_id' => $id, 'type' => (string) ($event['type'] ?? ''),
            'payload' => json_encode($event), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($stored === 0) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        $row = BillingEvent::query()->where('webhook_id', $id)->sole();

        [$outcome, $workspaceId] = DB::transaction(fn (): array => BillingEvents::apply($event));
        $row->forceFill(['outcome' => $outcome, 'workspace_id' => $workspaceId, 'processed_at' => now()])->save();

        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }

    /** GET /tantra/billing: the plan for the current workspace, start or manage it. */
    public function show(Request $request): Response
    {
        $workspace = Workspace::query()->find($request->user()->current_workspace_id);
        $plan = $workspace ? WorkspacePlan::for($workspace->id) : null;
        $status = $plan?->status ?? 'none';
        $label = ['none' => 'Not started', 'active' => 'Active', 'on_hold' => 'Payment problem', 'cancelled' => 'Cancelled', 'expired' => 'Ended', 'failed' => 'First payment failed', 'comped' => 'Included'][$status] ?? ucfirst($status);
        $line = match ($status) {
            'active' => 'Next charge on '.($plan->next_billing_at?->format('j M Y') ?? 'the billing date').'.',
            'on_hold' => 'Update your card before '.($plan->grace_ends_at?->format('j M Y') ?? 'the grace period ends').' to keep publishing.',
            'cancelled' => 'Runs until '.($plan->period_ends_at?->format('j M Y') ?? 'the end of the period').'.',
            'comped' => 'Content OS is included for this workspace.',
            default => 'End to end content automation: your ideas become posts for every channel, approved by you, published and tracked. '.config('tantra.billing.price_label').'.',
        };
        $action = in_array($status, ['active', 'on_hold', 'cancelled'], true) && $plan->dodo_customer_id
            ? '<a class="b" href="'.e(route('tantra.billing.portal')).'">Manage billing</a>'
            : ($status === 'comped' ? '' : '<form method="post" action="'.e(route('tantra.billing.checkout')).'"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><button class="b" type="submit">Start Content OS</button></form>');

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Content OS billing</title>'
            .'<style>body{font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;background:#f3f4f8;color:#12131a;margin:0;padding:48px 16px}main{background:#fff;border:1px solid #e5e7ef;border-radius:16px;padding:32px;max-width:520px;margin:0 auto}h1{font-size:24px;margin:16px 0 6px}.s{display:inline-block;padding:4px 10px;border-radius:999px;background:#efebff;color:#6d4aff;font-weight:600;font-size:13px}p{color:#3a3f4e;line-height:1.55}.b{display:inline-block;background:#6d4aff;color:#fff;border:0;border-radius:10px;padding:12px 20px;font-size:15px;font-weight:600;text-decoration:none;cursor:pointer}a{color:#6d4aff}</style></head><body><main>'
            .'<img src="'.e(Kit::asset('tantra/email/logo.png')).'" width="154" height="40" alt="Tantra"><h1>Content OS</h1><span class="s">'.e($label).'</span>'
            .'<p>'.e($line).'</p>'.$action.'<p><a href="'.e(Kit::appUrl('/tantra/review')).'">Back to the Engine</a></p></main></body></html>');
    }

    /** POST /tantra/billing/checkout: a Dodo checkout for the current workspace. */
    public function checkout(Request $request): RedirectResponse
    {
        $workspace = Workspace::query()->find($request->user()->current_workspace_id);
        abort_if($workspace === null, 404);

        return redirect()->away($this->checkoutUrl($workspace, (string) $request->user()->email, (string) $request->user()->name));
    }

    /** GET /tantra/billing/portal: Dodo's customer portal for the current workspace. */
    public function portal(Request $request): RedirectResponse
    {
        $plan = WorkspacePlan::query()->where('workspace_id', $request->user()->current_workspace_id)->first();
        abort_if($plan?->dodo_customer_id === null, 404);

        return redirect()->away(app(DodoClient::class)->portal($plan->dodo_customer_id));
    }

    /** GET /tantra/billing/{workspace}/manage (signed, from an email): the portal, to update the card. */
    public function manage(Workspace $workspace): RedirectResponse
    {
        $plan = WorkspacePlan::query()->where('workspace_id', $workspace->id)->first();
        if ($plan?->dodo_customer_id === null) {
            return redirect()->to(route('tantra.billing.show'));
        }

        return redirect()->away(app(DodoClient::class)->portal($plan->dodo_customer_id));
    }

    /** GET /tantra/billing/{workspace}/restart (signed, from an email): a new checkout for that workspace. */
    public function restart(Workspace $workspace): RedirectResponse
    {
        $plan = WorkspacePlan::query()->where('workspace_id', $workspace->id)->first();
        $owner = $workspace->owner;

        return redirect()->away($this->checkoutUrl($workspace, (string) ($plan?->billing_email ?? $owner?->email), (string) ($owner?->name ?? $workspace->name)));
    }

    private function checkoutUrl(Workspace $workspace, string $email, string $name): string
    {
        try {
            return app(DodoClient::class)->checkout($workspace->id, $email, $name, route('tantra.billing.show'))['checkout_url'];
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Checkout is not available right now. Try again in a minute.');
        }
    }
}
