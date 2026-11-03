<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Payment;
use ME\Abs\Models\PaymentTransaction;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Payments\CheckoutService;
use ME\Abs\Services\Payments\PaymentLedger;
use ME\Abs\Services\Payments\RefundService;
use ME\Abs\Tests\TestCase;

class PaymentTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
        config([
            'abs.gateway' => 'stripe',
            'abs.stripe.secret' => 'sk_test_x',
            'abs.stripe.webhook_secret' => self::SECRET,
            'abs.stripe.api_base' => 'https://stripe.test',
        ]);
    }

    private function onlineBooking(array $settings = ['payment_mode' => 'online_required']): Appointment
    {
        [$business, $service, $staff] = $this->bookableBusiness($settings + ['hold_minutes' => 30]);

        return app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $business, $service->id, $staff->id, null, $this->utc('2026-11-10 09:00'), 'Nadia', 'nadia@example.com',
        ));
    }

    private function fakeStripe(string $paymentStatus = 'paid', ?int $amount = null, string $currency = 'usd'): void
    {
        Http::fake([
            'stripe.test/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.test/cs_test_1']),
            'stripe.test/v1/checkout/sessions/*' => fn () => Http::response([
                'id' => 'cs_test_1', 'payment_status' => $paymentStatus, 'status' => 'complete',
                'amount_total' => $amount ?? Payment::query()->latest('id')->value('amount_minor'), 'currency' => $currency, 'payment_intent' => 'pi_test_1',
            ]),
            'stripe.test/v1/refunds' => Http::response(['id' => 're_test_1', 'status' => 'succeeded']),
        ]);
    }

    private function webhook(array $event, ?string $secret = self::SECRET, ?int $timestamp = null)
    {
        $payload = json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, (string) $secret);

        return $this->call('POST', route('abs.webhooks', 'stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function completed(string $eventId = 'evt_1'): array
    {
        return ['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid']]];
    }

    public function test_cash_payment_is_recorded_and_status_derived(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = Appointment::factory()->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'total_minor' => 5000]);
        $owner = $this->member($business);
        $ledger = app(PaymentLedger::class);

        $ledger->recordManual($appointment, 2000, 'cash', $owner);
        $this->assertSame(PaymentStatus::PartiallyPaid, $appointment->fresh()->payment_status);

        $ledger->recordManual($appointment, 3000, 'cash', $owner);
        $fresh = $appointment->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(0, $fresh->outstandingMinor());

        $this->expectException(PaymentException::class);
        $ledger->recordManual($appointment, 1, 'cash', $owner); // overpayment refused
    }

    public function test_cash_payment_through_admin_form_uses_server_amount_rules(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = Appointment::factory()->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'total_minor' => 5000]);
        $owner = $this->member($business);

        $this->actingInBusiness($owner, $business)
            ->post(route('abs.payments.store', $appointment->id), ['amount' => '80.00', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->post(route('abs.payments.store', $appointment->id), ['amount' => '50.00', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(5000, $appointment->fresh()->paid_minor);
    }

    public function test_online_payment_confirms_only_after_verified_webhook(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);

        $this->assertSame(5000, $payment->amount_minor);
        $this->assertSame('cs_test_1', $payment->fresh()->provider_reference);

        // Returning from the provider does not mark anything paid.
        $this->get(route('abs.public.payment.return', ['reference' => $appointment->booking_reference, 'payment' => $payment->id]))->assertForbidden();
        $this->get(URL::signedRoute('abs.public.payment.return', ['reference' => $appointment->booking_reference, 'payment' => $payment->id]))->assertRedirect();
        $this->assertSame(AppointmentStatus::AwaitingPayment, $appointment->fresh()->status);

        $this->webhook($this->completed())->assertOk()->assertJson(['result' => 'processed']);

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Confirmed, $fresh->status);
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(PaymentRecordStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('pi_test_1', $payment->fresh()->provider_payment_id);
    }

    public function test_paid_booking_in_approval_mode_waits_for_owner(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking(['payment_mode' => 'online_required', 'approval_mode' => 'approval']);
        app(CheckoutService::class)->start($appointment);

        $this->webhook($this->completed())->assertOk();

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $appointment->fresh()->payment_status);
    }

    public function test_deposit_checkout_charges_only_the_deposit(): void
    {
        $this->fakeStripe();
        [$business, $service, $staff] = $this->bookableBusiness(['payment_mode' => 'deposit_required', 'hold_minutes' => 30]);
        $service->update(['deposit_type' => 'fixed', 'deposit_value' => 1500]);
        $appointment = app(AppointmentBookingAction::class)->execute(new BookingRequest($business, $service->id, $staff->id, null, $this->utc('2026-11-10 09:00'), 'A', 'a@example.com'));

        $payment = app(CheckoutService::class)->start($appointment);
        $this->assertSame(1500, $payment->amount_minor);

        $this->webhook($this->completed())->assertOk();
        $this->assertSame(PaymentStatus::PartiallyPaid, $appointment->fresh()->payment_status);
        $this->assertSame(3500, $appointment->fresh()->outstandingMinor());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        app(CheckoutService::class)->start($appointment);

        $this->webhook($this->completed(), 'whsec_wrong')->assertStatus(400);
        $this->webhook($this->completed(), self::SECRET, time() - 3600)->assertStatus(400); // replayed old event
        $this->call('POST', route('abs.webhooks', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->completed()))->assertStatus(400);

        $this->assertSame(AppointmentStatus::AwaitingPayment, $appointment->fresh()->status);
        $this->assertDatabaseCount('abs_webhook_events', 0);
    }

    public function test_unknown_provider_webhook_is_404(): void
    {
        $this->post('/abs/webhooks/paypal', [])->assertNotFound();
    }

    public function test_duplicate_events_are_processed_once(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        app(CheckoutService::class)->start($appointment);

        $this->webhook($this->completed('evt_dup'))->assertJson(['result' => 'processed']);
        $this->webhook($this->completed('evt_dup'))->assertJson(['result' => 'duplicate']);
        // A different event id for the same checkout is also harmless (idempotent ledger).
        $this->webhook($this->completed('evt_other'))->assertOk();

        $this->assertSame(1, Payment::query()->where('status', 'succeeded')->count());
        $this->assertSame(1, PaymentTransaction::query()->where('type', 'charge')->count());
        $this->assertSame(5000, $appointment->fresh()->paid_minor);
    }

    public function test_webhook_body_is_not_trusted_provider_status_is_rechecked(): void
    {
        $this->fakeStripe(paymentStatus: 'unpaid');
        $appointment = $this->onlineBooking();
        app(CheckoutService::class)->start($appointment);

        $this->webhook($this->completed())->assertJson(['result' => 'ignored']);
        $this->assertSame(PaymentStatus::Unpaid, $appointment->fresh()->payment_status);
    }

    public function test_amount_or_currency_mismatch_is_not_recorded_as_paid(): void
    {
        $this->fakeStripe(amount: 1);
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);

        $this->webhook($this->completed())->assertOk();

        $this->assertSame(PaymentRecordStatus::Failed, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $appointment->fresh()->payment_status);
        $this->assertSame(AppointmentStatus::AwaitingPayment, $appointment->fresh()->status);
    }

    public function test_late_failure_event_does_not_override_success(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);

        $this->webhook($this->completed('evt_ok'))->assertOk();
        $this->webhook(['id' => 'evt_late', 'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_test_1']]])->assertOk();

        $this->assertSame(PaymentRecordStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_payment_after_hold_expired_and_slot_taken_is_refunded(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);

        // Hold expires, someone else books the same time at the desk.
        CarbonImmutable::setTestNow('2026-11-01 01:00:00');
        $other = app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $appointment->business, $appointment->service_id, $appointment->staff_id, null, $this->utc('2026-11-10 09:00'), 'Walk in', 'w@example.com', source: 'admin',
        ));
        $this->assertSame(AppointmentStatus::Confirmed, $other->status);

        $this->webhook($this->completed())->assertOk();

        $this->assertSame(AppointmentStatus::Expired, $appointment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $appointment->fresh()->payment_status);
        $this->assertDatabaseHas('abs_refunds', ['payment_id' => $payment->id, 'status' => 'completed', 'amount_minor' => 5000]);
    }

    public function test_partial_and_full_refunds(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);
        $this->webhook($this->completed())->assertOk();
        $refunds = app(RefundService::class);

        $refunds->request($payment->fresh(), 2000, 'Partial', null);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $appointment->fresh()->payment_status);
        $this->assertSame(PaymentRecordStatus::PartiallyRefunded, $payment->fresh()->status);

        try {
            $refunds->request($payment->fresh(), 3001, 'Too much', null);
            $this->fail('Refunded more than captured.');
        } catch (PaymentException) {
            $this->addToAssertionCount(1);
        }

        $refunds->request($payment->fresh(), 3000, 'Rest', null);
        $this->assertSame(PaymentStatus::Refunded, $appointment->fresh()->payment_status);
        $this->assertSame(5000, $appointment->fresh()->refunded_minor);
    }

    public function test_pending_refund_completes_by_webhook_once(): void
    {
        // First matching stub wins, so the pending refund response is registered before the defaults.
        Http::fake(['stripe.test/v1/refunds' => Http::response(['id' => 're_pending', 'status' => 'pending'])]);
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);
        $this->webhook($this->completed())->assertOk();

        $refund = app(RefundService::class)->request($payment->fresh(), 5000, null, null);
        $this->assertSame('pending', $refund->status->value);
        $this->assertSame(0, $appointment->fresh()->refunded_minor);

        $event = ['id' => 'evt_r1', 'type' => 'refund.updated', 'data' => ['object' => ['id' => 're_pending', 'status' => 'succeeded', 'metadata' => ['refund_id' => (string) $refund->id]]]];
        $this->webhook($event)->assertJson(['result' => 'processed']);
        $this->webhook(['id' => 'evt_r2'] + $event)->assertOk();

        $this->assertSame('completed', $refund->fresh()->status->value);
        $this->assertSame(5000, $appointment->fresh()->refunded_minor);
    }

    public function test_refund_requires_permission(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = Appointment::factory()->create(['staff_id' => $staff->id, 'business_id' => $business->id]);
        $receptionist = $this->member($business, BusinessRole::Receptionist);
        $payment = app(PaymentLedger::class)->recordManual($appointment, 5000, 'cash', $receptionist);

        $this->actingInBusiness($receptionist, $business)
            ->post(route('abs.payments.refund', $payment->id), ['amount' => '10'])
            ->assertForbidden();
        $this->assertDatabaseCount('abs_refunds', 0);
    }

    public function test_rejecting_a_paid_request_refunds_it(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking(['payment_mode' => 'online_required', 'approval_mode' => 'approval']);
        app(CheckoutService::class)->start($appointment);
        $this->webhook($this->completed())->assertOk();
        $owner = $this->member($appointment->business);

        $this->actingInBusiness($owner, $appointment->business)
            ->post(route('abs.appointments.transition', $appointment->id), ['status' => 'rejected', 'reason' => 'Closed'])
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Rejected, $appointment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $appointment->fresh()->payment_status);
    }

    public function test_expire_holds_command_releases_unpaid_bookings(): void
    {
        $this->fakeStripe();
        $appointment = $this->onlineBooking();
        $payment = app(CheckoutService::class)->start($appointment);

        CarbonImmutable::setTestNow('2026-11-01 00:31:00');
        $this->artisan('abs:expire-holds')->assertSuccessful();

        $this->assertSame(AppointmentStatus::Expired, $appointment->fresh()->status);
        $this->assertSame(PaymentRecordStatus::Cancelled, $payment->fresh()->status);
    }

    public function test_online_payment_cannot_be_chosen_without_a_gateway(): void
    {
        config(['abs.gateway' => null]);
        [$business] = $this->bookableBusiness();
        $owner = $this->member($business);

        $this->actingInBusiness($owner, $business)->put(route('abs.settings.update'), [
            'approval_mode' => 'instant', 'payment_mode' => 'online_required', 'min_notice_minutes' => 0, 'max_days_ahead' => 30,
            'slot_interval_minutes' => 15, 'cancellation_notice_minutes' => 0, 'reschedule_notice_minutes' => 0, 'hold_minutes' => 30,
            'tax_rate_percent' => '0', 'reminder_minutes_before' => 0,
        ])->assertSessionHasErrors('payment_mode');
    }
}
