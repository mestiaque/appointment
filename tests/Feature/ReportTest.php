<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Payments\PaymentLedger;
use ME\Abs\Services\Payments\RefundService;
use ME\Abs\Services\Reports\ReportService;
use ME\Abs\Support\CsvExporter;
use ME\Abs\Tests\TestCase;

class ReportTest extends TestCase
{
    public function test_summary_separates_gross_collected_refunds_and_outstanding(): void
    {
        CarbonImmutable::setTestNow('2026-11-15 12:00:00');
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        $at = fn (string $d, AppointmentStatus $s = AppointmentStatus::Confirmed) => Appointment::factory()->status($s)
            ->at($this->utc($d))->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'service_id' => $service->id, 'total_minor' => 10000]);

        $paid = $at('2026-11-10 09:00', AppointmentStatus::Completed);
        $at('2026-11-11 09:00');                                  // unpaid
        $at('2026-11-12 09:00', AppointmentStatus::Cancelled);    // not billable
        $at('2026-10-30 09:00');                                  // outside range
        [$other] = $this->bookableBusiness();                     // other business must not leak
        Appointment::factory()->create(['staff_id' => Staff::factory()->create(['business_id' => $other->id])->id, 'business_id' => $other->id, 'total_minor' => 999999]);

        $payment = app(PaymentLedger::class)->recordManual($paid, 10000, 'cash', $owner);
        app(RefundService::class)->request($payment, 2500, 'Goodwill', $owner);

        $reports = app(ReportService::class);
        $range = $reports->range($business, '2026-11-01', '2026-11-30');
        $summary = $reports->summary($business, $range['from'], $range['to']);
        $usd = $summary['money']['USD'];

        $this->assertSame(3, $summary['total_bookings']);
        $this->assertSame(20000, $usd['gross']);           // completed + unpaid confirmed, not the cancelled one
        $this->assertSame(10000, $usd['collected']);
        $this->assertSame(2500, $usd['refunded']);
        $this->assertSame(7500, $usd['net_collected']);
        $this->assertSame(12500, $usd['outstanding']);     // 2500 refunded back on the paid one + 10000 unpaid

        $byService = $reports->byService($business, $range['from'], $range['to']);
        $this->assertSame(3, (int) $byService->first()->bookings);
    }

    public function test_csv_cells_are_protected_against_formula_injection(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", CsvExporter::safe('=HYPERLINK("x")'));
        $this->assertSame("'+1", CsvExporter::safe('+1'));
        $this->assertSame("'@SUM(A1)", CsvExporter::safe('@SUM(A1)'));
        $this->assertSame('Normal', CsvExporter::safe('Normal'));
        $this->assertSame('', CsvExporter::safe(null));
    }

    public function test_export_streams_only_current_business_rows_with_safe_cells(): void
    {
        CarbonImmutable::setTestNow('2026-11-15 12:00:00');
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        Appointment::factory()->at($this->utc('2026-11-10 09:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'customer_name' => '=cmd|calc']);
        [$other] = $this->bookableBusiness();
        Appointment::factory()->at($this->utc('2026-11-10 09:00'))->create(['staff_id' => Staff::factory()->create(['business_id' => $other->id])->id, 'business_id' => $other->id, 'customer_name' => 'Other Business Customer']);

        $response = $this->actingInBusiness($owner, $business)->get(route('abs.reports.export', ['type' => 'appointments', 'from' => '2026-11-01', 'to' => '2026-11-30']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString('Other Business Customer', $csv);
    }
}
