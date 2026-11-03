<?php

namespace ME\Abs\Payments\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
 * SSLCommerz (v4) hosted payment page: cards, mobile banking and internet banking.
 *
 * provider_reference = our tran_id, provider_payment_id = bank_tran_id.
 * A payment counts only when SSLCommerz's validation API returns VALID/VALIDATED with the same amount and
 * currency. High-risk transactions (risk_level 1) are left pending for manual review.
 */
class SslCommerzGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'sslcommerz';
    }

    public function label(): string
    {
        return 'SSLCommerz (card / mobile / net banking)';
    }

    public static function fields(): array
    {
        return [
            'store_id' => ['label' => 'Store ID'],
            'store_password' => ['label' => 'Store password', 'secret' => true],
        ];
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function test(): string
    {
        $response = Http::timeout(20)->get($this->base().'/validator/api/merchantTransIDvalidationAPI.php', $this->auth() + ['tran_id' => 'ABS-CONNECTION-TEST', 'format' => 'json']);
        if (! $response->successful() || $response->json('APIConnect') !== 'DONE') {
            throw new PaymentException('SSLCommerz rejected the store credentials ('.($response->json('APIConnect') ?? 'HTTP '.$response->status()).').');
        }

        return 'Connected to SSLCommerz ('.($this->sandbox ? 'sandbox' : 'live').').';
    }

    public function createCheckout(Payment $payment, Appointment $appointment, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $tranId = 'ABS'.$payment->getKey().'-'.Str::upper(Str::random(6));
        $response = Http::asForm()->timeout(30)->post($this->base().'/gwprocess/v4/api.php', $this->auth() + [
            'total_amount' => $this->amount($payment->amount_minor, $payment->currency),
            'currency' => $payment->currency,
            'tran_id' => $tranId,
            'success_url' => $successUrl,
            'fail_url' => $successUrl,
            'cancel_url' => $successUrl,
            'ipn_url' => $this->business ? route('abs.webhooks', ['provider' => 'sslcommerz', 'business' => $this->business->getKey()]) : route('abs.webhooks', ['provider' => 'sslcommerz']),
            'cus_name' => mb_substr($appointment->customer_name, 0, 50),
            'cus_email' => $appointment->customer_email,
            'cus_phone' => $appointment->customer_phone ?: '01700000000',
            'cus_add1' => 'N/A',
            'cus_city' => 'N/A',
            'cus_country' => 'Bangladesh',
            'shipping_method' => 'NO',
            'num_of_item' => 1,
            'product_name' => mb_substr(($appointment->items->first()?->service_name ?? 'Appointment').' '.$appointment->booking_reference, 0, 100),
            'product_category' => 'Service',
            'product_profile' => 'non-physical-goods',
            'value_a' => (string) $payment->getKey(),
        ]);

        if (! $response->successful() || $response->json('status') !== 'SUCCESS' || ! $response->json('GatewayPageURL')) {
            throw new PaymentException('SSLCommerz could not start the payment: '.mb_substr((string) $response->json('failedreason', 'HTTP '.$response->status()), 0, 160));
        }

        return new CheckoutSession($tranId, (string) $response->json('GatewayPageURL'));
    }

    public function retrieveCheckout(string $reference): CheckoutStatus
    {
        $response = Http::timeout(30)->get($this->base().'/validator/api/merchantTransIDvalidationAPI.php', $this->auth() + ['tran_id' => $reference, 'format' => 'json']);
        if (! $response->successful() || $response->json('APIConnect') !== 'DONE') {
            throw new PaymentException('Could not verify the payment with SSLCommerz.');
        }

        $elements = collect($response->json('element', []));
        $valid = $elements->first(fn ($e) => in_array($e['status'] ?? null, ['VALID', 'VALIDATED'], true));

        if ($valid) {
            if ((string) ($valid['risk_level'] ?? '0') === '1') {
                return new CheckoutStatus(CheckoutStatus::OPEN, 0, 'BDT'); // held for manual review
            }

            return new CheckoutStatus(CheckoutStatus::PAID, $this->minor((string) ($valid['currency_amount'] ?? $valid['amount'] ?? '0'), (string) ($valid['currency_type'] ?? $valid['currency'] ?? 'BDT')),
                strtoupper((string) ($valid['currency_type'] ?? $valid['currency'] ?? 'BDT')), $valid['bank_tran_id'] ?? null);
        }

        $failed = $elements->isNotEmpty() && $elements->every(fn ($e) => in_array($e['status'] ?? null, ['FAILED', 'CANCELLED', 'UNATTEMPTED', 'EXPIRED'], true));

        return new CheckoutStatus($failed ? CheckoutStatus::FAILED : CheckoutStatus::OPEN, 0, 'BDT');
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        if (! $payment->provider_payment_id) {
            return new RefundResult(RefundStatus::Failed, null, 'Payment has no SSLCommerz bank transaction id.');
        }

        $response = Http::timeout(30)->get($this->base().'/validator/api/merchantTransIDvalidationAPI.php', $this->auth() + [
            'bank_tran_id' => $payment->provider_payment_id,
            'refund_amount' => $this->amount($refund->amount_minor, $refund->currency),
            'refund_remarks' => mb_substr($refund->reason ?: 'Booking refund', 0, 100),
            'refe_id' => 'ABSR'.$refund->getKey(),
            'format' => 'json',
        ]);

        if (! $response->successful() || $response->json('APIConnect') !== 'DONE' || $response->json('status') !== 'success') {
            return new RefundResult(RefundStatus::Failed, null, mb_substr((string) ($response->json('errorReason') ?? $response->json('status') ?? 'HTTP '.$response->status()), 0, 160));
        }

        // "success" = SSLCommerz accepted the refund; the bank settles it within a few working days.
        return new RefundResult(RefundStatus::Completed, $response->json('refund_ref_id'));
    }

    /**
     * IPN: authenticity comes from re-validating val_id with SSLCommerz, never from the posted fields.
     */
    public function parseWebhook(Request $request): GatewayEvent
    {
        $valId = (string) $request->input('val_id', '');
        $tranId = (string) $request->input('tran_id', '');
        if ($valId === '' || $tranId === '') {
            return new GatewayEvent('ssl-'.hash('sha256', $request->getContent()), 'ipn', GatewayEvent::IGNORED, null, null, hash('sha256', $request->getContent()));
        }

        $response = Http::timeout(30)->get($this->base().'/validator/api/validationserverAPI.php', $this->auth() + ['val_id' => $valId, 'format' => 'json']);
        if (! $response->successful() || ! in_array($response->json('status'), ['VALID', 'VALIDATED'], true) || $response->json('tran_id') !== $tranId) {
            throw new InvalidWebhookSignature('SSLCommerz did not validate this IPN.');
        }

        return new GatewayEvent('ssl-'.$valId, 'ipn.valid', GatewayEvent::CHECKOUT_COMPLETED, $tranId, null, hash('sha256', $request->getContent()));
    }

    private function base(): string
    {
        return $this->sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    /**
     * @return array{store_id: string, store_passwd: string}
     */
    private function auth(): array
    {
        return ['store_id' => (string) $this->cred('store_id'), 'store_passwd' => (string) $this->cred('store_password')];
    }

    private function minor(string $amount, string $currency): int
    {
        try {
            return Currency::toMinor($amount, $currency);
        } catch (InvalidArgumentException) {
            return -1;
        }
    }
}
