<?php

namespace ME\Abs\Actions;

use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Invoice;
use ME\Abs\Services\Payments\PaymentLedger;
use ME\Abs\Support\Currency;
use ME\Models\User;

/**
 * Staff-applied discount. Re-prices on the server with the tax rate snapshotted at booking time:
 * total = (subtotal − discount) + tax. A discount may not push the total below what was already collected
 * (refund first), and deposits are capped at the new total. An issued invoice is updated to the new totals.
 */
class AppointmentDiscountAction
{
    public function __construct(private PaymentLedger $ledger) {}

    public function execute(Appointment $appointment, int $discountMinor, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $discountMinor) {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [AppointmentStatus::Cancelled, AppointmentStatus::Rejected, AppointmentStatus::Expired], true)) {
                throw new BookingException('A closed booking cannot be re-priced.');
            }
            if ($discountMinor < 0 || $discountMinor > $locked->subtotal_minor) {
                throw new BookingException('Discount must be between 0 and the subtotal ('.$locked->money($locked->subtotal_minor).').');
            }

            $taxable = $locked->subtotal_minor - $discountMinor;
            $tax = Currency::percentOf($taxable, $locked->tax_rate_bps);
            $total = $taxable + $tax;
            $collected = $locked->paid_minor - $locked->refunded_minor;

            if ($total < $collected) {
                throw new BookingException('The new total ('.$locked->money($total).') is below the amount already paid. Refund the difference first.');
            }

            $before = $locked->only(['discount_minor', 'tax_minor', 'total_minor']);
            $locked->forceFill([
                'discount_minor' => $discountMinor,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'deposit_minor' => min($locked->deposit_minor, $total),
            ])->save();

            Invoice::query()->where('appointment_id', $locked->getKey())->update([
                'discount_minor' => $discountMinor, 'tax_minor' => $tax, 'total_minor' => $total,
            ]);

            $this->ledger->recalculate($locked);

            me_change_log('Discount on booking '.$locked->booking_reference, 'abs.appointment.discount')
                ->subject($locked)
                ->record($before, $locked->only(['discount_minor', 'tax_minor', 'total_minor']));

            $appointment->setRawAttributes($locked->getAttributes(), true);

            return $appointment;
        });
    }
}
