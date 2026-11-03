<?php

namespace ME\Abs\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Appointment;
use ME\Abs\Services\Payments\PaymentLedger;

/**
 * Releases unpaid online bookings whose hold expired. Availability already ignores expired holds,
 * so this only tidies status and cancels the open checkout; nothing depends on it running exactly on time.
 */
class ExpireHoldsCommand extends Command
{
    protected $signature = 'abs:expire-holds';

    protected $description = 'Expire unpaid booking holds';

    public function handle(AppointmentStateMachine $stateMachine, PaymentLedger $ledger): int
    {
        $count = 0;

        Appointment::query()
            ->where('status', AppointmentStatus::AwaitingPayment)
            ->where('hold_expires_at', '<=', CarbonImmutable::now())
            ->chunkById(200, function ($appointments) use ($stateMachine, $ledger, &$count) {
                foreach ($appointments as $appointment) {
                    try {
                        $stateMachine->transition($appointment, AppointmentStatus::Expired, ActorType::System, null, 'Payment not completed in time');
                        $appointment->payments()->where('status', PaymentRecordStatus::Pending)->get()
                            ->each(fn ($payment) => $ledger->markFailed($payment, 'Booking hold expired', 'system', PaymentRecordStatus::Cancelled));
                        $count++;
                    } catch (BookingException) {
                        // Paid (or otherwise moved on) between the query and the lock: nothing to expire.
                    }
                }
            });

        $this->info("Expired holds: {$count}");

        return self::SUCCESS;
    }
}
