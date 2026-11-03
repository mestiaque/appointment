<?php

namespace ME\Abs\Payments;

use Illuminate\Http\Request;
use ME\Abs\Exceptions\InvalidWebhookSignature;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Refund;

/**
 * Online payment provider adapter. The rest of the application only talks to this interface.
 */
interface PaymentGateway
{
    public function name(): string;

    public function label(): string;

    /**
     * Credential fields shown on the admin "Payment Gateways" page.
     *
     * @return array<string, array{label: string, secret?: bool, type?: string, required?: bool, help?: string}>
     */
    public static function fields(): array;

    /**
     * A copy of this gateway using a business's saved credentials.
     *
     * @param  array<string, string|null>  $credentials
     */
    public function withCredentials(array $credentials, bool $sandbox, ?Business $business = null): static;

    public function isConfigured(): bool;

    /**
     * ISO currencies the provider accepts, or null for "any".
     *
     * @return array<int, string>|null
     */
    public function currencies(): ?array;

    public function supportsRefunds(): bool;

    /**
     * True when the payment is confirmed by a server-side call after the customer returns
     * (bKash execute, SSLCommerz validation, Nagad verify, PayPal capture). Never by trusting the redirect.
     */
    public function capturesOnReturn(): bool;

    /**
     * Create a hosted checkout for $payment. Must be idempotent per payment.
     */
    public function createCheckout(Payment $payment, Appointment $appointment, string $successUrl, string $cancelUrl): CheckoutSession;

    /**
     * Ask the provider for the real state of a checkout (and complete it where the provider needs that).
     */
    public function retrieveCheckout(string $reference): CheckoutStatus;

    public function refund(Payment $payment, Refund $refund): RefundResult;

    /**
     * Verify the signature/authenticity and normalise the event.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): GatewayEvent;

    /**
     * Check the saved credentials against the provider. Returns a short success message or throws.
     *
     * @throws PaymentException
     */
    public function test(): string;
}
