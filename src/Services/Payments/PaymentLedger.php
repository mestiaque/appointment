<?php

namespace ME\Abs\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\ApprovalMode;
use ME\Abs\Enums\PaymentPurpose;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Enums\RefundStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Payment;
use ME\Abs\Models\PaymentTransaction;
use ME\Abs\Payments\CheckoutStatus;
use ME\Abs\Services\Notifications\AppointmentNotifier;
use ME\Models\User;

/**
 * Every change to payment state goes through here, inside a transaction with the payment row locked.
 * Appointment status and payment status stay separate: a successful payment moves an awaiting_payment
 * booking to pending (approval mode) or confirmed (instant) — it never skips owner approval.
 */
class PaymentLedger
{
    public function __construct(
        private AppointmentStateMachine $stateMachine,
        private AppointmentNotifier $notifier,
    ) {}

    /**
     * Cash or other offline payment recorded by staff.
     */
    public function recordManual(Appointment $appointment, int $amountMinor, string $method, User $actor, ?string $notes = null): Payment
    {
        if (! in_array($method, ['cash', 'manual'], true)) {
            throw new PaymentException('Unknown payment method.');
        }

        return DB::transaction(function () use ($appointment, $amountMinor, $method, $actor, $notes) {
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();

            if ($amountMinor < 1 || $amountMinor > $locked->outstandingMinor()) {
                throw new PaymentException('Amount must be between 1 and the outstanding balance ('.$locked->money($locked->outstandingMinor()).').');
            }

            $payment = Payment::query()->create([
                'business_id' => $locked->business_id,
                'appointment_id' => $locked->getKey(),
                'provider' => $method,
                'purpose' => $amountMinor >= $locked->outstandingMinor() && $locked->paid_minor === 0 ? PaymentPurpose::Full : PaymentPurpose::Balance,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'status' => PaymentRecordStatus::Succeeded,
                'paid_at' => CarbonImmutable::now(),
                'recorded_by_user_id' => $actor->getKey(),
                'notes' => $notes ? mb_substr($notes, 0, 255) : null,
            ]);

            $this->log($payment, 'charge', 'succeeded', $amountMinor, null, 'manual');
            $this->recalculate($locked);
            $this->notifier->paymentReceivedAfterCommit($payment);

            return $payment;
        });
    }

    /**
     * Provider confirmed the money was captured. Idempotent: repeated or late events change nothing.
     */
    public function markSucceeded(Payment $payment, CheckoutStatus $status, string $source): Payment
    {
        $lateRefund = false;

        $payment = DB::transaction(function () use ($payment, $status, $source, &$lateRefund) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, PaymentRecordStatus::captured(), true)) {
                return $locked;
            }

            if ($status->amountMinor !== $locked->amount_minor || strtoupper($status->currency) !== $locked->currency) {
                Log::error('abs: payment amount/currency mismatch', ['payment_id' => $locked->getKey()]);
                $locked->forceFill([
                    'status' => PaymentRecordStatus::Failed,
                    'failed_at' => CarbonImmutable::now(),
                    'failure_reason' => 'Provider amount or currency did not match; needs manual review.',
                ])->save();
                $this->log($locked, 'failure', 'mismatch', $status->amountMinor, $status->providerPaymentId, $source);

                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentRecordStatus::Succeeded,
                'paid_at' => CarbonImmutable::now(),
                'provider_payment_id' => $status->providerPaymentId,
                'failure_reason' => null,
            ])->save();
            $this->log($locked, 'charge', 'succeeded', $locked->amount_minor, $status->providerPaymentId, $source);

            $appointment = Appointment::query()->whereKey($locked->appointment_id)->lockForUpdate()->firstOrFail();
            $this->recalculate($appointment);

            if ($appointment->status === AppointmentStatus::AwaitingPayment) {
                $next = $appointment->approval_mode === ApprovalMode::Approval ? AppointmentStatus::Pending : AppointmentStatus::Confirmed;
                try {
                    $this->stateMachine->transition($appointment, $next, ActorType::System, null, 'Online payment received');
                } catch (BookingException) {
                    // The hold expired and the slot was taken before the payment was confirmed.
                    $this->stateMachine->transition($appointment, AppointmentStatus::Expired, ActorType::System, null, 'Slot taken before payment was confirmed');
                    $lateRefund = true;
                }
            } elseif (in_array($appointment->status, [AppointmentStatus::Expired, AppointmentStatus::Cancelled, AppointmentStatus::Rejected], true)) {
                // Paid after the hold expired or the booking ended: the slot is not guaranteed, so refund in full.
                $lateRefund = true;
            }

            $this->notifier->paymentReceivedAfterCommit($locked);

            return $locked;
        });

        if ($lateRefund) {
            app(RefundService::class)->request($payment, $payment->amount_minor, 'Automatic refund: booking was no longer active when payment arrived.', null);
        }

        return $payment;
    }

    public function markFailed(Payment $payment, string $reason, string $source, PaymentRecordStatus $as = PaymentRecordStatus::Failed): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $source, $as) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // Out-of-order safety: a late "failed"/"expired" event never overrides a captured payment.
            if ($locked->status !== PaymentRecordStatus::Pending) {
                return $locked;
            }

            $locked->forceFill(['status' => $as, 'failed_at' => CarbonImmutable::now(), 'failure_reason' => mb_substr($reason, 0, 255)])->save();
            $this->log($locked, $as === PaymentRecordStatus::Cancelled ? 'cancel' : 'failure', $as->value, $locked->amount_minor, null, $source);

            if ($as === PaymentRecordStatus::Failed) {
                $this->notifier->paymentFailedAfterCommit($locked);
            }

            return $locked;
        });
    }

    /**
     * Refund completed (provider or manual): reduce the payment and recompute the appointment.
     * Caller holds the refund row lock and guarantees this runs once per refund.
     */
    public function applyCompletedRefund(Payment $payment, int $amountMinor, ?string $providerReference, string $source): void
    {
        /** @var Payment $locked */
        $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
        $refunded = min($locked->amount_minor, $locked->refunded_minor + $amountMinor);

        $locked->forceFill([
            'refunded_minor' => $refunded,
            'status' => $refunded >= $locked->amount_minor ? PaymentRecordStatus::Refunded : PaymentRecordStatus::PartiallyRefunded,
        ])->save();
        $this->log($locked, 'refund', RefundStatus::Completed->value, $amountMinor, $providerReference, $source);

        $this->recalculate(Appointment::query()->whereKey($locked->appointment_id)->lockForUpdate()->firstOrFail());
    }

    /**
     * Recompute paid / refunded totals and payment_status from the payment rows (single source of truth).
     */
    public function recalculate(Appointment $appointment): void
    {
        $payments = Payment::query()->where('appointment_id', $appointment->getKey())
            ->whereIn('status', array_map(fn ($s) => $s->value, PaymentRecordStatus::captured()))
            ->get(['amount_minor', 'refunded_minor']);

        $paid = (int) $payments->sum('amount_minor');
        $refunded = (int) $payments->sum('refunded_minor');

        $status = match (true) {
            $refunded > 0 && $refunded >= $paid => PaymentStatus::Refunded,
            $refunded > 0 => PaymentStatus::PartiallyRefunded,
            $paid > 0 && $paid >= $appointment->total_minor => PaymentStatus::Paid,
            $paid > 0 => PaymentStatus::PartiallyPaid,
            default => PaymentStatus::Unpaid,
        };

        $appointment->forceFill(['paid_minor' => $paid, 'refunded_minor' => $refunded, 'payment_status' => $status])->save();
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function log(Payment $payment, string $type, string $status, int $amountMinor, ?string $reference, string $source, array $meta = []): void
    {
        PaymentTransaction::query()->create([
            'payment_id' => $payment->getKey(),
            'type' => $type,
            'status' => $status,
            'amount_minor' => $amountMinor,
            'provider_reference' => $reference,
            'source' => $source,
            'meta' => $meta ?: null,
        ]);
    }
}
