<?php

namespace ME\Abs\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
 * bKash Tokenized Checkout (v1.2.0-beta) over REST.
 *
 * Flow: grant token → create (customer pays on bKash page) → callback → execute (server-side) → Completed.
 * provider_reference = paymentID, provider_payment_id = trxID (needed for refunds).
 * There is no webhook: the execute / status call is the verification.
 */
class BkashGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
    }

    public static function fields(): array
    {
        return [
            'username' => ['label' => 'Username'],
            'password' => ['label' => 'Password', 'secret' => true],
            'app_key' => ['label' => 'App key'],
            'app_secret' => ['label' => 'App secret', 'secret' => true],
        ];
    }

    public function currencies(): ?array
    {
        return ['BDT'];
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function test(): string
    {
        $this->token(fresh: true);

        return 'Connected to bKash ('.($this->sandbox ? 'sandbox' : 'live').'): token granted.';
    }

    public function createCheckout(Payment $payment, Appointment $appointment, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $response = $this->client()->post('/tokenized/checkout/create', [
            'mode' => '0011',
            'payerReference' => preg_replace('/\D+/', '', (string) $appointment->customer_phone) ?: $appointment->booking_reference,
            'callbackURL' => $successUrl,
            'amount' => $this->amount($payment->amount_minor, $payment->currency),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => 'ABS'.$payment->getKey(),
        ]);

        if (! $this->ok($response) || ! $response->json('paymentID') || ! $response->json('bkashURL')) {
            throw new PaymentException('bKash could not start the payment: '.$this->error($response));
        }

        return new CheckoutSession((string) $response->json('paymentID'), (string) $response->json('bkashURL'));
    }

    public function retrieveCheckout(string $reference): CheckoutStatus
    {
        $status = $this->client()->post('/tokenized/checkout/payment/status', ['paymentID' => $reference]);
        if (! $status->successful()) {
            throw new PaymentException('Could not verify the payment with bKash.');
        }

        $data = $status->json();
        if (($data['transactionStatus'] ?? null) === 'Initiated') {
            // The customer authorised on bKash: execute captures the money (idempotent on bKash's side).
            $execute = $this->client()->post('/tokenized/checkout/execute', ['paymentID' => $reference]);
            if ($execute->successful() && $execute->json('transactionStatus')) {
                $data = $execute->json();
            } elseif ($execute->json('statusCode') && $execute->json('statusCode') !== '0000') {
                return new CheckoutStatus(CheckoutStatus::FAILED, 0, 'BDT');
            }
        }

        $state = match ($data['transactionStatus'] ?? null) {
            'Completed' => CheckoutStatus::PAID,
            'Failed', 'Cancelled', 'Expired', 'Declined' => CheckoutStatus::FAILED,
            default => CheckoutStatus::OPEN,
        };

        return new CheckoutStatus($state, $this->minor((string) ($data['amount'] ?? '0')), strtoupper((string) ($data['currency'] ?? 'BDT')), $data['trxID'] ?? null);
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        if (! $payment->provider_payment_id || ! $payment->provider_reference) {
            return new RefundResult(RefundStatus::Failed, null, 'Payment has no bKash transaction id.');
        }

        $response = $this->client()->post('/tokenized/checkout/payment/refund', [
            'paymentID' => $payment->provider_reference,
            'trxID' => $payment->provider_payment_id,
            'amount' => $this->amount($refund->amount_minor, $refund->currency),
            'sku' => 'booking',
            'reason' => mb_substr($refund->reason ?: 'Booking refund', 0, 100),
        ]);

        if (! $this->ok($response)) {
            return new RefundResult(RefundStatus::Failed, null, $this->error($response));
        }

        return new RefundResult(
            $response->json('transactionStatus') === 'Completed' ? RefundStatus::Completed : RefundStatus::Pending,
            $response->json('refundTrxID'),
        );
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        throw new InvalidWebhookSignature('bKash payments are confirmed on return; no webhook is used.');
    }

    private function base(): string
    {
        return $this->sandbox ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta' : 'https://tokenized.pay.bka.sh/v1.2.0-beta';
    }

    private function token(bool $fresh = false): string
    {
        $key = 'abs:bkash-token:'.md5($this->base().$this->cred('app_key').$this->cred('username'));
        if ($fresh) {
            Cache::forget($key);
        }

        // Tokens live one hour; refresh a little earlier.
        return Cache::remember($key, 50 * 60, function () {
            $response = Http::baseUrl($this->base())->acceptJson()->asJson()->timeout(20)
                ->withHeaders(['username' => (string) $this->cred('username'), 'password' => (string) $this->cred('password')])
                ->post('/tokenized/checkout/token/grant', ['app_key' => $this->cred('app_key'), 'app_secret' => $this->cred('app_secret')]);

            if (! $response->successful() || ! $response->json('id_token')) {
                throw new PaymentException('bKash rejected the credentials: '.$this->error($response));
            }

            return (string) $response->json('id_token');
        });
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->base())->acceptJson()->asJson()->timeout(30)
            ->withHeaders(['Authorization' => $this->token(), 'X-App-Key' => (string) $this->cred('app_key')]);
    }

    private function ok(Response $response): bool
    {
        return $response->successful() && in_array($response->json('statusCode'), ['0000', null], true) && ! $response->json('errorCode');
    }

    private function error(Response $response): string
    {
        return mb_substr((string) ($response->json('statusMessage') ?? $response->json('errorMessage') ?? $response->json('message') ?? 'HTTP '.$response->status()), 0, 160);
    }

    private function minor(string $amount): int
    {
        try {
            return Currency::toMinor($amount, 'BDT');
        } catch (InvalidArgumentException) {
            return -1; // forces an amount mismatch, never a false "paid"
        }
    }
}
