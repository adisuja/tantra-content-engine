<?php

declare(strict_types=1);

namespace App\Tantra\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** tantra: the few Dodo Payments calls Content OS billing makes. Test or live by DODO_ENV. */
final class DodoClient
{
    public static function configured(): bool
    {
        return filled(config('tantra.billing.api_key'));
    }

    public function baseUrl(): string
    {
        return config('tantra.billing.environment') === 'live_mode' ? 'https://live.dodopayments.com' : 'https://test.dodopayments.com';
    }

    /**
     * A hosted checkout for Content OS. The workspace id rides in the metadata,
     * which Dodo copies onto the subscription and every payment.
     *
     * @return array{session_id: string, checkout_url: string}
     */
    public function checkout(string $workspaceId, string $email, string $name, string $returnUrl): array
    {
        $response = $this->http()->post('/checkouts', [
            'product_cart' => [['product_id' => (string) config('tantra.billing.product_id'), 'quantity' => 1]],
            'customer' => ['email' => $email, 'name' => $name],
            'return_url' => $returnUrl,
            'metadata' => ['workspace_id' => $workspaceId, 'app' => 'tantra-content-engine', 'plan' => 'content_os'],
        ]);
        if (! $response->successful() || blank($response->json('checkout_url'))) {
            throw new RuntimeException('Dodo did not create a checkout (HTTP '.$response->status().').');
        }

        return ['session_id' => (string) $response->json('session_id'), 'checkout_url' => (string) $response->json('checkout_url')];
    }

    /** The customer portal: update the card, see invoices, cancel. */
    public function portal(string $customerId): string
    {
        $response = $this->http()->post('/customers/'.rawurlencode($customerId).'/customer-portal/session');
        if (! $response->successful() || blank($response->json('link'))) {
            throw new RuntimeException('Dodo did not open the customer portal (HTTP '.$response->status().').');
        }

        return (string) $response->json('link');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->withToken((string) config('tantra.billing.api_key'))->acceptJson()->asJson()->timeout(20)->retry(2, 500, throw: false);
    }
}
