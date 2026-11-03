<?php

namespace ME\Abs\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Invoice;
use ME\Abs\Models\Sequence;

/**
 * One invoice per appointment, numbered per business without gaps from a locked counter row.
 */
class InvoiceService
{
    public function issue(Appointment $appointment): Invoice
    {
        if ($existing = $appointment->invoice()->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($appointment) {
            Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->first();
            if ($existing = Invoice::query()->where('appointment_id', $appointment->getKey())->first()) {
                return $existing;
            }

            $number = Sequence::next($appointment->business_id, 'invoice');

            return Invoice::query()->create([
                'business_id' => $appointment->business_id,
                'appointment_id' => $appointment->getKey(),
                'number' => 'INV-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                'issued_at' => CarbonImmutable::now(),
                'currency' => $appointment->currency,
                'subtotal_minor' => $appointment->subtotal_minor,
                'discount_minor' => $appointment->discount_minor,
                'tax_minor' => $appointment->tax_minor,
                'total_minor' => $appointment->total_minor,
            ]);
        });
    }
}
