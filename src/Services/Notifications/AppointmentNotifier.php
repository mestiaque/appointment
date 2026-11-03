<?php

namespace ME\Abs\Services\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\AppointmentRescheduleHistory;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Refund;
use ME\Abs\Notifications\AppointmentNotification;
use ME\Abs\Support\Currency;
use Throwable;

/**
 * Decides who is told what, and sends only after the surrounding transaction commits
 * (a rolled-back booking never e-mails anyone).
 */
class AppointmentNotifier
{
    private bool $muted = false;

    /**
     * Run $callback without sending any booking notification (demo seeding, imports).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function muted(callable $callback): mixed
    {
        $previous = $this->muted;
        $this->muted = true;

        try {
            return $callback();
        } finally {
            $this->muted = $previous;
        }
    }

    public function bookedAfterCommit(Appointment $appointment): void
    {
        match ($appointment->status) {
            AppointmentStatus::Pending => $this->both($appointment, 'booking_received'),
            AppointmentStatus::Confirmed => $this->both($appointment, 'booking_confirmed'),
            default => null,
        };
    }

    public function statusChangedAfterCommit(Appointment $appointment, AppointmentStatus $from, AppointmentStatus $to): void
    {
        match (true) {
            $to === AppointmentStatus::Pending && $from === AppointmentStatus::AwaitingPayment => $this->both($appointment, 'booking_received'),
            $to === AppointmentStatus::Confirmed && $from === AppointmentStatus::Pending => $this->customer($appointment, 'booking_approved'),
            $to === AppointmentStatus::Confirmed && $from === AppointmentStatus::AwaitingPayment => $this->both($appointment, 'booking_confirmed'),
            $to === AppointmentStatus::Rejected => $this->customer($appointment, 'booking_rejected', ['reason' => $appointment->cancellation_reason]),
            $to === AppointmentStatus::Cancelled => $this->both($appointment, 'booking_cancelled'),
            default => null,
        };
    }

    public function rescheduledAfterCommit(Appointment $appointment, AppointmentRescheduleHistory $history): void
    {
        $this->both($appointment, 'appointment_rescheduled', [], (string) $history->getKey());
    }

    public function reminder(Appointment $appointment): void
    {
        $this->customer($appointment, 'appointment_reminder', [], $appointment->starts_at->timestamp);
    }

    public function paymentReceivedAfterCommit(Payment $payment): void
    {
        $this->customer($payment->appointment, 'payment_received', ['amount' => Currency::format($payment->amount_minor, $payment->currency)], 'p'.$payment->getKey());
    }

    public function paymentFailedAfterCommit(Payment $payment): void
    {
        $this->customer($payment->appointment, 'payment_failed', [], 'p'.$payment->getKey());
    }

    public function refundUpdatedAfterCommit(Refund $refund): void
    {
        $this->customer($refund->payment->appointment, 'refund_status', [
            'amount' => Currency::format($refund->amount_minor, $refund->currency),
            'status' => $refund->status->value,
        ], 'r'.$refund->getKey().':'.$refund->status->value);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function both(Appointment $appointment, string $event, array $extra = [], string|int $suffix = ''): void
    {
        $this->customer($appointment, $event, $extra, $suffix);
        $this->business($appointment, $event, $extra, $suffix);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function customer(Appointment $appointment, string $event, array $extra = [], string|int $suffix = ''): void
    {
        $this->afterCommit(function () use ($appointment, $event, $extra, $suffix) {
            $notifiable = $appointment->customer ?? Notification::route('mail', [$appointment->customer_email => $appointment->customer_name]);
            $notifiable->notify(new AppointmentNotification($appointment->getKey(), $event, 'customer', "a{$appointment->getKey()}:{$event}:c:{$suffix}", $extra));
        });
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function business(Appointment $appointment, string $event, array $extra = [], string|int $suffix = ''): void
    {
        $this->afterCommit(function () use ($appointment, $event, $extra, $suffix) {
            $business = $appointment->business;
            $email = $business->email ?: $business->owner?->email;
            if (! $email) {
                return;
            }

            Notification::route('mail', $email)
                ->notify(new AppointmentNotification($appointment->getKey(), $event, 'business', "a{$appointment->getKey()}:{$event}:b:{$suffix}", $extra));
        });
    }

    private function afterCommit(callable $callback): void
    {
        if ($this->muted) {
            return;
        }

        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                // A notification problem must never undo or fail a booking. Log without customer data.
                Log::warning('abs: notification dispatch failed', ['error' => $e->getMessage()]);
            }
        });
    }
}
