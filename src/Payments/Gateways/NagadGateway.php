<?php

namespace ME\Abs\Payments\Gateways;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
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
 * Nagad Payment Gateway (check-out API v-0.2.0).
 *
 * Sensitive data is RSA-encrypted with Nagad's public key and signed (SHA256withRSA) with the merchant
 * private key. Flow: initialize → complete (redirect to Nagad) → callback → verify (server-side).
 * provider_reference = paymentReferenceId, provider_payment_id = issuerPaymentRefNo.
 * Refunds are not available through this integration: refund from the Nagad merchant portal, then record it.
 */
class NagadGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'nagad';
    }

    public function label(): string
    {
        return 'Nagad';
    }

    public static function fields(): array
    {
        return [
            'merchant_id' => ['label' => 'Merchant ID'],
            'merchant_number' => ['label' => 'Merchant account number'],
            'merchant_private_key' => ['label' => 'Merchant private key', 'secret' => true, 'type' => 'textarea', 'help' => 'PEM or the base64 key from Nagad.'],
            'pg_public_key' => ['label' => 'Nagad PG public key', 'type' => 'textarea', 'help' => 'PEM or base64, provided by Nagad.'],
        ];
    }

    public function currencies(): ?array
    {
        return ['BDT'];
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function test(): string
    {
        if (! openssl_pkey_get_private($this->pem((string) $this->cred('merchant_private_key'), 'PRIVATE KEY'))) {
            throw new PaymentException('The merchant private key could not be read.');
        }
        if (! openssl_pkey_get_public($this->pem((string) $this->cred('pg_public_key'), 'PUBLIC KEY'))) {
            throw new PaymentException('The Nagad public key could not be read.');
        }

        return 'Keys are valid. A live Nagad call is made on the first real payment ('.($this->sandbox ? 'sandbox' : 'live').').';
    }

    public function createCheckout(Payment $payment, Appointment $appointment, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $merchantId = (string) $this->cred('merchant_id');
        $orderId = substr('ABS'.$payment->getKey().'T'.time(), 0, 20);
        $dateTime = CarbonImmutable::now('Asia/Dhaka')->format('YmdHis');

        $init = $this->client()->post("/api/dfs/check-out/initialize/{$merchantId}/{$orderId}?locale=EN", [
            'accountNumber' => $this->cred('merchant_number'),
            'dateTime' => $dateTime,
            'sensitiveData' => $this->encrypt($sensitive = ['merchantId' => $merchantId, 'datetime' => $dateTime, 'orderId' => $orderId, 'challenge' => bin2hex(random_bytes(20))]),
            'signature' => $this->sign($sensitive),
        ]);

        $decrypted = $init->successful() && $init->json('sensitiveData') ? $this->decrypt((string) $init->json('sensitiveData')) : null;
        if (! $decrypted || empty($decrypted['paymentReferenceId']) || empty($decrypted['challenge'])) {
            throw new PaymentException('Nagad could not start the payment: '.mb_substr((string) ($init->json('message') ?? 'HTTP '.$init->status()), 0, 160));
        }

        $order = [
            'merchantId' => $merchantId,
            'orderId' => $orderId,
            'currencyCode' => '050',
            'amount' => $this->amount($payment->amount_minor, $payment->currency),
            'challenge' => $decrypted['challenge'],
        ];
        $complete = $this->client()->post('/api/dfs/check-out/complete/'.rawurlencode($decrypted['paymentReferenceId']), [
            'sensitiveData' => $this->encrypt($order),
            'signature' => $this->sign($order),
            'merchantCallbackURL' => $successUrl,
            'additionalMerchantInfo' => ['booking' => $appointment->booking_reference],
        ]);

        if (! $complete->successful() || $complete->json('status') !== 'Success' || ! $complete->json('callBackUrl')) {
            throw new PaymentException('Nagad could not start the payment: '.mb_substr((string) ($complete->json('message') ?? 'HTTP '.$complete->status()), 0, 160));
        }

        return new CheckoutSession((string) $decrypted['paymentReferenceId'], (string) $complete->json('callBackUrl'));
    }

    public function retrieveCheckout(string $reference): CheckoutStatus
    {
        $response = $this->client()->get('/api/dfs/verify/payment/'.rawurlencode($reference));
        if (! $response->successful()) {
            throw new PaymentException('Could not verify the payment with Nagad.');
        }

        $state = match ($response->json('status')) {
            'Success' => CheckoutStatus::PAID,
            'Aborted', 'Cancelled', 'Failed', 'OrderNotFound' => CheckoutStatus::FAILED,
            default => CheckoutStatus::OPEN,
        };

        try {
            $minor = Currency::toMinor((string) $response->json('amount', '0'), 'BDT');
        } catch (InvalidArgumentException) {
            $minor = -1;
        }

        return new CheckoutStatus($state, $minor, 'BDT', $response->json('issuerPaymentRefNo'));
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        return new RefundResult(RefundStatus::Failed, null, 'Nagad refunds are made from the Nagad merchant portal. Then record the refund as done outside the system.');
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        throw new InvalidWebhookSignature('Nagad payments are verified on return; no webhook is used.');
    }

    private function base(): string
    {
        return $this->sandbox ? 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0' : 'https://api.mynagad.com';
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->base())->acceptJson()->asJson()->timeout(30)->withHeaders([
            'X-KM-Api-Version' => 'v-0.2.0',
            'X-KM-IP-V4' => request()?->ip() ?? '127.0.0.1',
            'X-KM-Client-Type' => 'PC_WEB',
        ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function encrypt(array $data): string
    {
        $key = openssl_pkey_get_public($this->pem((string) $this->cred('pg_public_key'), 'PUBLIC KEY'));
        if (! $key || ! openssl_public_encrypt(json_encode($data, JSON_UNESCAPED_SLASHES), $encrypted, $key)) {
            throw new PaymentException('Nagad public key is not valid.');
        }

        return base64_encode($encrypted);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function sign(array $data): string
    {
        $key = openssl_pkey_get_private($this->pem((string) $this->cred('merchant_private_key'), 'PRIVATE KEY'));
        if (! $key || ! openssl_sign(json_encode($data, JSON_UNESCAPED_SLASHES), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PaymentException('Merchant private key is not valid.');
        }

        return base64_encode($signature);
    }

    /**
     * @return array<string, string>|null
     */
    private function decrypt(string $data): ?array
    {
        $key = openssl_pkey_get_private($this->pem((string) $this->cred('merchant_private_key'), 'PRIVATE KEY'));
        if (! $key || ! openssl_private_decrypt((string) base64_decode($data, true), $plain, $key)) {
            return null;
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function pem(string $key, string $type): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN {$type}-----\n".chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")."-----END {$type}-----\n";
    }
}
