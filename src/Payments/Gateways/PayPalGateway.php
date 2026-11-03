<?php

namespace ME\Abs\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ME\Abs\Enums\RefundStatus;
use ME\Abs\Exceptions\InvalidWebhookSignature;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Refund;
use ME\Abs\Payments\CheckoutSession;
use ME\Abs\Payments\CheckoutStatus;
use ME\Abs\Payments\GatewayEvent;
use ME\Abs\Payments\RefundResult;
use ME\Abs\Support\Currency;

/**
 * PayPal Checkout (Orders API v2) over REST, no SDK.
 *
 * An approved order is not money yet: retrieveCheckout() captures an APPROVED order server-side and reports
 * paid only when PayPal returns a COMPLETED capture. Webhooks are verified by PayPal's
 * /v1/notifications/verify-webhook-signature endpoint with the configured webhook id.
 * provider_reference = order id, provider_payment_id = capture id (used for refunds).
 */
class PayPalGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public static function fields(): array
    {
        return [
            'client_id' => ['label' => 'Client ID'],
            'secret' => ['label' => 'Secret', 'secret' => true],
            'webhook_id' => ['label' => 'Webhook ID', 'help' => 'From the PayPal developer dashboard, for the webhook URL shown below.'],
        ];
    }

    protected function legacyConfig(): array
    {
        return ['client_id' => 'abs.paypal.client_id', 'secret' => 'abs.paypal.secret', 'webhook_id' => 'abs.paypal.webhook_id'];
    }

    public function test(): string
    {
        $this->accessToken($this->base(), fresh: true);

        return 'Connected to PayPal ('.($this->fromDatabase ? ($this->sandbox ? 'sandbox' : 'live') : 'configured').').';
    }

    private function base(): string
    {
        if ($this->fromDatabase) {
            return $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        }

        return rtrim((string) config('abs.paypal.api_base'), '/');
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function capturesOnReturn(): bool
    {
        return true;
    }

    public function createCheckout(Payment $payment, Appointment $appointment, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => 'abs-checkout-'.$payment->getKey()])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $appointment->booking_reference,
                    'custom_id' => (string) $payment->getKey(),
                    'description' => mb_substr(($appointment->items->first()?->service_name ?? 'Appointment').' — '.$appointment->booking_reference, 0, 127),
                    'amount' => ['currency_code' => $payment->currency, 'value' => Currency::toDecimal($payment->amount_minor, $payment->currency)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'return_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ]]],
            ]);

        $approve = collect($response->json('links', []))->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));

        if (! $response->successful() || ! $response->json('id') || ! $approve) {
            throw new PaymentException('The payment provider could not start the checkout. Please try again later.');
        }

        return new CheckoutSession($response->json('id'), $approve['href']);
    }

    public function retrieveCheckout(string $reference): CheckoutStatus
    {
        $order = $this->client()->get('/v2/checkout/orders/'.rawurlencode($reference));
        if (! $order->successful()) {
            throw new PaymentException('Could not verify the payment with the provider.');
        }

        $data = $order->json();
        if (($data['status'] ?? null) === 'APPROVED') {
            $capture = $this->client()
                ->withHeaders(['PayPal-Request-Id' => 'abs-capture-'.$reference])
                ->post('/v2/checkout/orders/'.rawurlencode($reference).'/capture', (object) []);
            if (! $capture->successful()) {
                throw new PaymentException('The payment could not be captured.');
            }
            $data = $capture->json();
        }

        $captureData = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        $state = match (true) {
            ($data['status'] ?? null) === 'COMPLETED' && ($captureData['status'] ?? null) === 'COMPLETED' => CheckoutStatus::PAID,
            in_array($captureData['status'] ?? null, ['DECLINED', 'FAILED'], true) => CheckoutStatus::FAILED,
            ($data['status'] ?? null) === 'VOIDED' => CheckoutStatus::EXPIRED,
            default => CheckoutStatus::OPEN,
        };

        $amount = $captureData['amount'] ?? ($data['purchase_units'][0]['amount'] ?? []);
        $currency = strtoupper((string) ($amount['currency_code'] ?? ''));

        try {
            $minor = $currency ? Currency::toMinor((string) ($amount['value'] ?? '0'), $currency) : 0;
        } catch (InvalidArgumentException) {
            $minor = -1; // forces a mismatch, never a false "paid"
        }

        return new CheckoutStatus($state, $minor, $currency, $captureData['id'] ?? null);
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        if (! $payment->provider_payment_id) {
            return new RefundResult(RefundStatus::Failed, null, 'Payment has no capture id.');
        }

        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => 'abs-refund-'.$refund->getKey()])
            ->post('/v2/payments/captures/'.rawurlencode($payment->provider_payment_id).'/refund', [
                'amount' => ['currency_code' => $refund->currency, 'value' => Currency::toDecimal($refund->amount_minor, $refund->currency)],
                'custom_id' => (string) $refund->getKey(),
            ]);

        if (! $response->successful()) {
            return new RefundResult(RefundStatus::Failed, null, mb_substr((string) $response->json('message', 'Refund request failed.'), 0, 250));
        }

        return new RefundResult($this->refundStatus((string) $response->json('status')), $response->json('id'));
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['id'], $event['event_type'])) {
            throw new InvalidWebhookSignature('Malformed webhook payload.');
        }

        $headers = [
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
        ];
        if (in_array(null, $headers, true) || in_array('', $headers, true)) {
            throw new InvalidWebhookSignature('Missing webhook signature headers.');
        }

        $verification = $this->client()->post('/v1/notifications/verify-webhook-signature', $headers + [
            'webhook_id' => $this->cred('webhook_id'),
            'webhook_event' => $event,
        ]);
        if (! $verification->successful() || $verification->json('verification_status') !== 'SUCCESS') {
            throw new InvalidWebhookSignature('Webhook signature could not be verified.');
        }

        $resource = $event['resource'] ?? [];
        $type = (string) $event['event_type'];
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        [$kind, $reference, $refundStatus, $localRefund] = match ($type) {
            'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED' => [GatewayEvent::CHECKOUT_COMPLETED, $resource['id'] ?? null, null, null],
            'PAYMENT.CAPTURE.COMPLETED' => [GatewayEvent::CHECKOUT_COMPLETED, $orderId, null, null],
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => [GatewayEvent::CHECKOUT_FAILED, $orderId, null, null],
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.REFUND.COMPLETED', 'PAYMENT.REFUND.FAILED', 'PAYMENT.REFUND.CANCELLED', 'PAYMENT.REFUND.PENDING' => [
                GatewayEvent::REFUND_UPDATED, $resource['id'] ?? null, $this->refundStatus((string) ($resource['status'] ?? '')),
                ctype_digit((string) ($resource['custom_id'] ?? '')) ? (int) $resource['custom_id'] : null,
            ],
            default => [GatewayEvent::IGNORED, null, null, null],
        };

        return new GatewayEvent((string) $event['id'], $type, $kind, $reference, $refundStatus, hash('sha256', $payload), $localRefund);
    }

    private function refundStatus(string $status): RefundStatus
    {
        return match (strtoupper($status)) {
            'COMPLETED' => RefundStatus::Completed,
            'FAILED', 'CANCELLED' => RefundStatus::Failed,
            default => RefundStatus::Pending,
        };
    }

    private function client(): PendingRequest
    {
        $base = $this->base();

        return Http::baseUrl($base)->withToken($this->accessToken($base))->acceptJson()->asJson()->timeout(20)->retry(2, 500, throw: false);
    }

    private function accessToken(string $base, bool $fresh = false): string
    {
        $key = 'abs:paypal-token:'.md5($base.$this->cred('client_id'));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 300, function () use ($base) {
            $response = Http::baseUrl($base)->asForm()->withBasicAuth((string) $this->cred('client_id'), (string) $this->cred('secret'))
                ->timeout(20)->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new PaymentException('Could not authenticate with the payment provider.');
            }

            return (string) $response->json('access_token');
        });
    }
}
