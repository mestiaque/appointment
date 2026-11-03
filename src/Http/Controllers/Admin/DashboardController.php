<?php

namespace ME\Abs\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\View\View;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Http\Controllers\Admin\Concerns\FindsAppointments;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Services\Reports\ReportService;

class DashboardController extends Controller
{
    use FindsAppointments;

    public function index(ReportService $reports): View
    {
        $business = $this->business();
        $now = CarbonImmutable::now();
        $todayLocal = CarbonImmutable::now($business->timezone);

        $upcoming = $this->visibleAppointments()
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Pending->value, AppointmentStatus::CheckedIn->value])
            ->where('starts_at', '>=', $now->subHour())
            ->with('staff:id,name', 'items:id,appointment_id,service_name')
            ->orderBy('starts_at')->limit(10)->get();

        $pendingApproval = $this->visibleAppointments()->where('status', AppointmentStatus::Pending->value)->count();
        $today = $this->visibleAppointments()
            ->where('starts_at', '>=', $todayLocal->startOfDay()->utc())
            ->where('starts_at', '<', $todayLocal->addDay()->startOfDay()->utc())
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::Rejected->value, AppointmentStatus::Expired->value])
            ->count();

        $range = $reports->range($business, null, null);
        $summary = $this->canInBusiness('abs_report.view') ? $reports->summary($business, $range['from'], $range['to']) : null;

        return view('abs::admin.dashboard', compact('business', 'upcoming', 'pendingApproval', 'today', 'summary'));
    }
}
