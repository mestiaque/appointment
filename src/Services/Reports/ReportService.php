<?php

namespace ME\Abs\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Enums\RefundStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Refund;

/**
 * Business reports. Every query is filtered by business_id first.
 *
 * Terms (kept separate on purpose):
 *  - gross booking value: totals of billable bookings (pending, confirmed, checked in, in progress, completed, no-show)
 *    whose start is in the range — what was booked, not what was paid;
 *  - collected: captured payments whose paid_at is in the range;
 *  - refunds: completed refunds whose processed_at is in the range;
 *  - outstanding: for the same billable bookings, total minus (paid - refunded), never below zero.
 * Amounts are grouped by currency because a business may have changed currency over time.
 */
class ReportService
{
    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function range(Business $business, ?string $from, ?string $to): array
    {
        $timezone = $business->timezone;
        $fromLocal = $this->date($from, $timezone) ?? CarbonImmutable::now($timezone)->startOfMonth();
        $toLocal = $this->date($to, $timezone) ?? CarbonImmutable::now($timezone)->endOfMonth()->startOfDay();

        if ($toLocal->lessThan($fromLocal)) {
            [$fromLocal, $toLocal] = [$toLocal, $fromLocal];
        }
        if ($fromLocal->diffInDays($toLocal) > 366) {
            $toLocal = $fromLocal->addDays(366);
        }

        return ['from' => $fromLocal->startOfDay()->utc(), 'to' => $toLocal->addDay()->startOfDay()->utc()];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Business $business, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $appointments = $this->appointments($business, $from, $to);

        $byStatus = (clone $appointments)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');

        $billable = (clone $appointments)->whereIn('status', $this->values(AppointmentStatus::billable()));
        $money = (clone $billable)
            ->select('currency', DB::raw('SUM(total_minor) as gross'), DB::raw($this->outstandingSql().' as outstanding'))
            ->groupBy('currency')->get()->keyBy('currency');

        $collected = Payment::query()->where('business_id', $business->getKey())
            ->whereIn('status', $this->values(PaymentRecordStatus::captured()))
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->select('currency', DB::raw('SUM(amount_minor) as total'))->groupBy('currency')->pluck('total', 'currency');

        $refunds = Refund::query()->where('business_id', $business->getKey())
            ->where('status', RefundStatus::Completed)
            ->where('processed_at', '>=', $from)->where('processed_at', '<', $to)
            ->select('currency', DB::raw('SUM(amount_minor) as total'))->groupBy('currency')->pluck('total', 'currency');

        $currencies = collect([$business->currency])->merge($money->keys())->merge($collected->keys())->merge($refunds->keys())->unique()->values();

        return [
            'counts' => collect(AppointmentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)])->all(),
            'total_bookings' => (int) $byStatus->sum(),
            'money' => $currencies->mapWithKeys(fn ($currency) => [$currency => [
                'gross' => (int) ($money[$currency]->gross ?? 0),
                'outstanding' => (int) ($money[$currency]->outstanding ?? 0),
                'collected' => (int) ($collected[$currency] ?? 0),
                'refunded' => (int) ($refunds[$currency] ?? 0),
                'net_collected' => (int) ($collected[$currency] ?? 0) - (int) ($refunds[$currency] ?? 0),
            ]])->all(),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    public function byService(Business $business, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->grouped($business, $from, $to, 'service_id')
            ->leftJoin('abs_services', 'abs_services.id', '=', 'abs_appointments.service_id')
            ->addSelect(DB::raw("COALESCE(abs_services.name, 'Deleted service') as label"))
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function byStaff(Business $business, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->grouped($business, $from, $to, 'staff_id')
            ->leftJoin('abs_staff', 'abs_staff.id', '=', 'abs_appointments.staff_id')
            ->addSelect(DB::raw("COALESCE(abs_staff.name, 'Deleted staff') as label"))
            ->get();
    }

    /**
     * @return Builder<Appointment>
     */
    public function appointments(Business $business, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Appointment::query()->where('abs_appointments.business_id', $business->getKey())
            ->where('abs_appointments.starts_at', '>=', $from)->where('abs_appointments.starts_at', '<', $to);
    }

    /**
     * @return Builder<Appointment>
     */
    private function grouped(Business $business, CarbonImmutable $from, CarbonImmutable $to, string $column): Builder
    {
        $billable = implode(',', array_map(fn ($s) => DB::getPdo()->quote($s->value), AppointmentStatus::billable()));

        return $this->appointments($business, $from, $to)
            ->select(
                "abs_appointments.{$column} as group_id",
                'abs_appointments.currency',
                DB::raw('COUNT(*) as bookings'),
                DB::raw("SUM(CASE WHEN abs_appointments.status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN abs_appointments.status IN ('cancelled','rejected') THEN 1 ELSE 0 END) as cancelled"),
                DB::raw("SUM(CASE WHEN abs_appointments.status IN ({$billable}) THEN abs_appointments.total_minor ELSE 0 END) as gross"),
                DB::raw("SUM(CASE WHEN abs_appointments.status IN ({$billable}) THEN abs_appointments.paid_minor - abs_appointments.refunded_minor ELSE 0 END) as net_paid"),
            )
            ->groupBy("abs_appointments.{$column}", 'abs_appointments.currency', 'label')
            ->orderByDesc('bookings');
    }

    private function outstandingSql(): string
    {
        return 'SUM(CASE WHEN total_minor > paid_minor - refunded_minor THEN total_minor - (paid_minor - refunded_minor) ELSE 0 END)';
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<int, string>
     */
    private function values(array $cases): array
    {
        return array_map(fn ($case) => $case->value, $cases);
    }

    private function date(?string $value, string $timezone): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
