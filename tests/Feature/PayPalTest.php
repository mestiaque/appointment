<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Payments\CheckoutService;
use ME\Abs\Services\Payments\RefundService;
use ME\Abs\Tests\TestCase;

/**
 * PayPal adapter against faked PayPal REST responses (no live account available).
 */
class PayPalTest extends TestCase
{
    private string $orderStatus = 'APPROVED';

    private string $captureValue = '50.00';

    private string $verification = 'SUCCESS';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
        config([
            'abs.gateway' => 'paypal',
            'abs.paypal.client_id' => 'client', 'abs.paypal.secret' => 'secret', 'abs.paypal.webhook_id' => 'WH-1',
            'abs.paypal.api_base' => 'https://paypal.test',
        ]);

        $completed = fn () => [
            'id' => 'ORDER-1', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => $this->captureValue]]]]]],
        ];

        Http::fake([
            'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'paypal.test/v1/notifications/verify-webhook-signature' => fn () => Http::response(['verification_status' => $this->verification]),
            'paypal.test/v2/checkout/orders/ORDER-1/capture' => fn () => Http::response($completed()),
            'paypal.test/v2/checkout/orders/ORDER-1' => fn () => Http::response($this->orderStatus === 'APPROVED' ? ['id' => 'ORDER-1', 'status' => 'APPROVED'] : $completed()),
            'paypal.test/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/checkoutnow?token=ORDER-1']]]),
            'paypal.test/v2/payments/captures/CAP-1/refund' => Http::response(['id' => 'REF-1', 'status' => 'COMPLETED']),
        ]);
    }

    private function booking(): Appointment
    {
        [$business, $service, $staff] = $this->bookableBusiness(['payment_mode' => 'online_required', 'hold_minutes' => 30]);

        return app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $business, $service->id, $staff->id, null, $this->utc('2026-11-10 09:00'), 'Omar', 'omar@example.com',
        ));
    }

    private function returnUrl(Appointment $appointment, int $paymentId): string
    {
        return URL::signedRoute('abs.public.payment.return', ['reference' => $appointment->booking_reference, 'payment' => $paymentId]);
    }

    private function webhook(array $event)
    {
        return $this->postJson(route('abs.webhooks', 'paypal'), $event, [
            'PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-TIME' => now()->toIso8601String(),
            'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-TRANSMISSION-SIG' => 'sig',
        ]);
    }

    public function test_checkout_is_created_and_return_captures_server_side(): void
    {
        $appointment = $this->booking();
        $payment = app(CheckoutService::class)->start($appointment);

        $this->assertSame('ORDER-1', $payment->fresh()->provider_reference);
        $this->assertSame('https://paypal.test/checkoutnow?token=ORDER-1', $payment->fresh()->checkout_url);
        Http::assertSent(fn ($r) => $r->url() === 'https://paypal.test/v2/checkout/orders' && $r['purchase_units'][0]['amount']['value'] === '50.00');

        $this->get($this->returnUrl($appointment, $payment->id))->assertRedirect();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ORDER-1/capture'));
        $this->assertSame(PaymentRecordStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('CAP-1', $payment->fresh()->provider_payment_id);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_captured_amount_mismatch_is_not_marked_paid(): void
    {
        $this->captureValue = '1.00';
        $appointment = $this->booking();
        $payment = app(CheckoutService::class)->start($appointment);

        $this->get($this->returnUrl($appointment, $payment->id));

        $this->assertSame(PaymentRecordStatus::Failed, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $appointment->fresh()->payment_status);
    }

    public function test_verified_webhook_confirms_and_duplicates_are_ignored(): void
    {
        $appointment = $this->booking();
        app(CheckoutService::class)->start($appointment);
        $event = ['id' => 'WH-EVT-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1']];

        $this->webhook($event)->assertOk()->assertJson(['result' => 'processed']);
        $this->webhook($event)->assertOk()->assertJson(['result' => 'duplicate']);

        $this->assertSame(PaymentStatus::Paid, $appointment->fresh()->payment_status);
    }

    public function test_unverified_webhook_is_rejected(): void
    {
        $this->verification = 'FAILURE';
        $appointment = $this->booking();
        app(CheckoutService::class)->start($appointment);

        $this->webhook(['id' => 'WH-EVT-2', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1']])->assertStatus(400);
        $this->postJson(route('abs.webhooks', 'paypal'), ['id' => 'x', 'event_type' => 'CHECKOUT.ORDER.APPROVED'])->assertStatus(400);

        $this->assertSame(PaymentStatus::Unpaid, $appointment->fresh()->payment_status);
    }

    public function test_cancelled_payment_is_never_captured_by_a_late_approval(): void
    {
        $appointment = $this->booking();
        $payment = app(CheckoutService::class)->start($appointment);
        CarbonImmutable::setTestNow('2026-11-01 00:31:00');
        $this->artisan('abs:expire-holds')->assertSuccessful();

        $this->webhook(['id' => 'WH-EVT-3', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1']])->assertJson(['result' => 'ignored']);
        $this->get($this->returnUrl($appointment, $payment->id));

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/capture'));
        $this->assertSame(PaymentRecordStatus::Cancelled, $payment->fresh()->status);
    }

    public function test_refund_through_paypal(): void
    {
        $appointment = $this->booking();
        $payment = app(CheckoutService::class)->start($appointment);
        $this->get($this->returnUrl($appointment, $payment->id));

        $refund = app(RefundService::class)->request($payment->fresh(), 2000, 'Partial', null);

        $this->assertSame('completed', $refund->status->value);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/captures/CAP-1/refund') && $r['amount']['value'] === '20.00');
        $this->assertSame(PaymentStatus::PartiallyRefunded, $appointment->fresh()->payment_status);
    }
}
