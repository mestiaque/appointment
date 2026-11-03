<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\View\View;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Payment;
use ME\Abs\Services\Reports\ReportService;
use ME\Abs\Support\CsvExporter;
use ME\Abs\Support\Currency;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): View
    {
        $business = $this->business();
        $range = $reports->range($business, $request->query('from'), $request->query('to'));

        return view('abs::admin.reports.index', [
            'business' => $business,
            'from' => $range['from']->setTimezone($business->timezone)->toDateString(),
            'to' => $range['to']->setTimezone($business->timezone)->subDay()->toDateString(),
            'summary' => $reports->summary($business, $range['from'], $range['to']),
            'services' => $reports->byService($business, $range['from'], $range['to']),
            'staff' => $reports->byStaff($business, $range['from'], $range['to']),
            'canExport' => $this->canInBusiness('abs_report.export'),
        ]);
    }

    public function export(Request $request, string $type, ReportService $reports): StreamedResponse
    {
        $business = $this->business();
        $range = $reports->range($business, $request->query('from'), $request->query('to'));
        $tz = $business->timezone;
        $name = "{$business->slug}-{$type}-".now()->format('Ymd-His').'.csv';
        me_change_log('Booking report exported: '.$type, 'abs.report.export')->record([], ['type' => $type, 'from' => $request->query('from'), 'to' => $request->query('to')]);

        return match ($type) {
            'appointments' => CsvExporter::download($name,
                ['Reference', 'Start (local)', 'End (local)', 'Timezone', 'Status', 'Payment status', 'Customer', 'Email', 'Phone', 'Staff', 'Service', 'Currency', 'Total', 'Paid', 'Refunded', 'Outstanding'],
                function ($write) use ($reports, $business, $range, $tz) {
                    $reports->appointments($business, $range['from'], $range['to'])
                        ->with('staff:id,name', 'items:id,appointment_id,service_name')
                        ->chunkById(500, function ($rows) use ($write, $tz) {
                            foreach ($rows as $a) {
                                $write([
                                    $a->booking_reference, $a->starts_at->setTimezone($tz)->format('Y-m-d H:i'), $a->ends_at->setTimezone($tz)->format('Y-m-d H:i'), $tz,
                                    $a->status->value, $a->payment_status->value, $a->customer_name, $a->customer_email, $a->customer_phone,
                                    $a->staff?->name, $a->items->first()?->service_name, $a->currency,
                                    Currency::toDecimal($a->total_minor, $a->currency), Currency::toDecimal($a->paid_minor, $a->currency),
                                    Currency::toDecimal($a->refunded_minor, $a->currency), Currency::toDecimal($a->outstandingMinor(), $a->currency),
                                ]);
                            }
                        }, 'abs_appointments.id', 'id');
                }),
            'payments' => CsvExporter::download($name,
                ['Payment #', 'Booking', 'Provider', 'Purpose', 'Status', 'Currency', 'Amount', 'Refunded', 'Paid at (local)'],
                function ($write) use ($business, $range, $tz) {
                    Payment::query()->where('business_id', $business->getKey())
                        ->whereIn('status', array_map(fn ($s) => $s->value, PaymentRecordStatus::captured()))
                        ->where('paid_at', '>=', $range['from'])->where('paid_at', '<', $range['to'])
                        ->with('appointment:id,booking_reference')
                        ->chunkById(500, function ($rows) use ($write, $tz) {
                            foreach ($rows as $p) {
                                $write([$p->id, $p->appointment?->booking_reference, $p->provider, $p->purpose->value, $p->status->value, $p->currency,
                                    Currency::toDecimal($p->amount_minor, $p->currency), Currency::toDecimal($p->refunded_minor, $p->currency),
                                    $p->paid_at?->setTimezone($tz)->format('Y-m-d H:i')]);
                            }
                        });
                }),
            'services', 'staff' => CsvExporter::download($name,
                [$type === 'services' ? 'Service' : 'Staff', 'Currency', 'Bookings', 'Completed', 'Cancelled/rejected', 'Gross booking value', 'Net paid'],
                function ($write) use ($reports, $business, $range, $type) {
                    $rows = $type === 'services' ? $reports->byService($business, $range['from'], $range['to']) : $reports->byStaff($business, $range['from'], $range['to']);
                    foreach ($rows as $r) {
                        $write([$r->label, $r->currency, $r->bookings, $r->completed, $r->cancelled, Currency::toDecimal((int) $r->gross, $r->currency), Currency::toDecimal((int) $r->net_paid, $r->currency)]);
                    }
                }),
        };
    }
}
