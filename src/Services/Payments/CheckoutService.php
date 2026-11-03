<?php

namespace ME\Abs\Services\Payments;

use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentPurpose;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Payment;
use ME\Abs\Payments\GatewayManager;

/**
 * Starts a hosted online payment. The amount always comes from the appointment on the server
 * (deposit while awaiting payment with a deposit, otherwise the outstanding balance).
 */
class CheckoutService
{
    public function __construct(private GatewayManager $gateways, private PaymentLedger $ledger) {}

    /**
     * @param  string|null  $provider  gateway chosen by the customer; the first enabled one when null
     */
    public function start(Appointment $appointment, ?string $provider = null): Payment
    {
        $available = $this->gateways->forBusiness($appointment->business);
        $gateway = $provider ? $available->get($provider) : $available->first();
        if (! $gateway) {
            throw new PaymentException($available->isEmpty() ? 'Online payment is not available right now.' : 'That payment method is not available.');
        }

        if (in_array($appointment->status, [AppointmentStatus::Cancelled, AppointmentStatus::Rejected, AppointmentStatus::Expired, AppointmentStatus::NoShow], true)) {
            throw new PaymentException('This booking can no longer be paid online.');
        }

        $payment = DB::transaction(function () use ($appointment, $gateway) {
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();

            $isDeposit = $locked->status === AppointmentStatus::AwaitingPayment && $locked->deposit_minor > 0 && $locked->paid_minor === 0;
            $amount = $isDeposit ? $locked->deposit_minor : $locked->outstandingMinor();

            if ($amount < 1) {
                throw new PaymentException('Nothing is due for this booking.');
            }

            // Only one open checkout per booking: older pending attempts are cancelled.
            Payment::query()->where('appointment_id', $locked->getKey())->where('status', PaymentRecordStatus::Pending)
                ->get()->each(fn (Payment $old) => $this->ledger->markFailed($old, 'Superseded by a new checkout', 'system', PaymentRecordStatus::Cancelled));

            return Payment::query()->create([
                'business_id' => $locked->business_id,
                'appointment_id' => $locked->getKey(),
                'provider' => $gateway->name(),
                'purpose' => $isDeposit ? PaymentPurpose::Deposit : ($locked->paid_minor > 0 ? PaymentPurpose::Balance : PaymentPurpose::Full),
                'amount_minor' => $amount,
                'currency' => $locked->currency,
                'status' => PaymentRecordStatus::Pending,
            ]);
        });

        $session = $gateway->createCheckout(
            $payment,
            $appointment->loadMissing('items'),
            $payment->callbackUrl(),
            $gateway->capturesOnReturn() ? $payment->callbackUrl() : $appointment->manageUrl(),
        );

        $payment->forceFill(['provider_reference' => $session->reference, 'checkout_url' => $session->url])->save();
        $this->ledger->log($payment, 'checkout', 'created', $payment->amount_minor, $session->reference, 'system');

        return $payment;
    }
}
