<?php

namespace ME\Abs\Payments\Gateways;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

/**
 * Stripe Checkout (hosted page) over the REST API, no SDK. Card data never touches this server.
 * Webhook signature: HMAC-SHA256 of "{timestamp}.{raw body}" with the endpoint secret (Stripe-Signature header).
 */
class StripeGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Card (Stripe)';
    }

    public static function fields(): array
    {
        return [
            'secret_key' => ['label' => 'Secret key', 'secret' => true, 'help' => 'sk_live_… or sk_test_…'],
            'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true, 'help' => 'whsec_…'],
        ];
    }

    protected function legacyConfig(): array
    {
        return ['secret_key' => 'abs.stripe.secret', 'webhook_secret' => 'abs.stripe.webhook_secret'];
    }

    public function test(): string
    {
        $response = $this->client()->get('/v1/balance');
        if (! $response->successful()) {
            throw new PaymentException('Stripe rejected the key: '.mb_substr((string) $response->json('error.message', 'HTTP '.$response->status()), 0, 160));
        }

        return 'Connected to Stripe ('.($response->json('livemode') ? 'live' : 'test').' mode).';
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function capturesOnReturn(): bool
    {
        return false;
    }

    public function createCheckout(Payment $payment, Appointment $appointment, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => 'abs-checkout-'.$payment->getKey()])
            ->asForm()
            ->post('/v1/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => (string) $payment->getKey(),
                'customer_email' => $appointment->customer_email,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                // Stripe requires 30 minutes to 24 hours.
                'expires_at' => CarbonImmutable::now()->addMinutes(31)->timestamp,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($payment->currency),
                        'unit_amount' => $payment->amount_minor,
                        'product_data' => ['name' => mb_substr($appointment->items->first()?->service_name ?? 'Appointment', 0, 120).' — '.$appointment->booking_reference],
                    ],
                ]],
                'metadata' => [
                    'payment_id' => (string) $payment->getKey(),
                    'booking_reference' => $appointment->booking_reference,
                ],
            ]);

        if (! $response->successful() || ! $response->json('id') || ! $response->json('url')) {
            throw new PaymentException('The payment provider could not start the checkout. Please try again later.');
        }

        return new CheckoutSession($response->json('id'), $response->json('url'));
    }

    public function retrieveCheckout(string $reference): CheckoutStatus
    {
        $response = $this->client()->get('/v1/checkout/sessions/'.rawurlencode($reference));

        if (! $response->successful()) {
            throw new PaymentException('Could not verify the payment with the provider.');
        }

        $state = match (true) {
            $response->json('payment_status') === 'paid' => CheckoutStatus::PAID,
            $response->json('status') === 'expired' => CheckoutStatus::EXPIRED,
            default => CheckoutStatus::OPEN,
        };

        return new CheckoutStatus(
            $state,
            (int) $response->json('amount_total'),
            strtoupper((string) $response->json('currency')),
            is_string($response->json('payment_intent')) ? $response->json('payment_intent') : null,
        );
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        if (! $payment->provider_payment_id) {
            return new RefundResult(RefundStatus::Failed, null, 'Payment has no provider payment id.');
        }

        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => 'abs-refund-'.$refund->getKey()])
            ->asForm()
            ->post('/v1/refunds', [
                'payment_intent' => $payment->provider_payment_id,
                'amount' => $refund->amount_minor,
                'metadata' => ['refund_id' => (string) $refund->getKey()],
            ]);

        if (! $response->successful()) {
            return new RefundResult(RefundStatus::Failed, null, mb_substr((string) $response->json('error.message', 'Refund request failed.'), 0, 250));
        }

        return new RefundResult($this->refundStatus((string) $response->json('status')), $response->json('id'));
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        $payload = $request->getContent();
        $this->verifySignature($payload, (string) $request->header('Stripe-Signature'));

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new InvalidWebhookSignature('Malformed webhook payload.');
        }

        $object = $event['data']['object'] ?? [];
        $type = (string) $event['type'];

        [$kind, $reference, $refundStatus] = match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => [GatewayEvent::CHECKOUT_COMPLETED, $object['id'] ?? null, null],
            'checkout.session.async_payment_failed' => [GatewayEvent::CHECKOUT_FAILED, $object['id'] ?? null, null],
            'checkout.session.expired' => [GatewayEvent::CHECKOUT_EXPIRED, $object['id'] ?? null, null],
            'refund.created', 'refund.updated', 'refund.failed' => [GatewayEvent::REFUND_UPDATED, $object['id'] ?? null, $this->refundStatus((string) ($object['status'] ?? ''))],
            default => [GatewayEvent::IGNORED, null, null],
        };

        $localRefundId = $kind === GatewayEvent::REFUND_UPDATED && ctype_digit((string) ($object['metadata']['refund_id'] ?? ''))
            ? (int) $object['metadata']['refund_id']
            : null;

        return new GatewayEvent((string) $event['id'], $type, $kind, $reference, $refundStatus, hash('sha256', $payload), $localRefundId);
    }

    public function verifySignature(string $payload, string $header): void
    {
        $secret = (string) $this->cred('webhook_secret');
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($secret === '' || ! $timestamp || ! ctype_digit($timestamp) || $signatures === []) {
            throw new InvalidWebhookSignature('Missing webhook signature.');
        }

        if (abs(time() - (int) $timestamp) > config('abs.stripe.webhook_tolerance', 300)) {
            throw new InvalidWebhookSignature('Webhook timestamp outside tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidWebhookSignature('Webhook signature mismatch.');
    }

    private function refundStatus(string $status): RefundStatus
    {
        return match ($status) {
            'succeeded' => RefundStatus::Completed,
            'failed', 'canceled' => RefundStatus::Failed,
            default => RefundStatus::Pending,
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('abs.stripe.api_base'), '/'))
            ->withToken((string) $this->cred('secret_key'))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }
}
