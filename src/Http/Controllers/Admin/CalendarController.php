<?php

namespace ME\Abs\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use ME\Abs\Actions\AppointmentReschedulingAction;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Http\Controllers\Admin\Concerns\FindsAppointments;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;
use ME\Abs\Support\BusinessAccess;

/**
 * Appointment calendar: one colour per staff member, working hours shaded, click for quick actions,
 * drag to reschedule (validated by AppointmentReschedulingAction), click an empty slot to book.
 */
class CalendarController extends Controller
{
    use FindsAppointments;

    private const PALETTE = ['#2563eb', '#db2777', '#059669', '#d97706', '#7c3aed', '#0891b2', '#dc2626', '#65a30d', '#4f46e5', '#c2410c'];

    public function index(): View
    {
        $business = $this->business();
        $restricted = app(BusinessAccess::class)->restrictedStaffId(auth()->user(), $business);
        $staff = $business->staff()->with('schedules')->orderBy('sort_order')->orderBy('id')
            ->when($restricted !== null, fn ($q) => $q->whereKey($restricted))
            ->get(['id', 'name', 'title', 'is_active']);

        $schedules = $staff->flatMap->schedules;
        $allStaff = $business->staff()->withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id']);

        return view('abs::admin.appointments.calendar', [
            'business' => $business,
            'staff' => $staff->map(fn (Staff $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'title' => $s->title,
                'color' => $this->colorFor($s->id, $allStaff),
            ])->values(),
            'businessHours' => $schedules->map(fn ($row) => [
                'daysOfWeek' => [$row->weekday],
                'startTime' => substr($row->start_time, 0, 5),
                'endTime' => substr($row->end_time, 0, 5),
            ])->values(),
            'hiddenDays' => $schedules->isEmpty() ? [] : array_values(array_diff(range(0, 6), $schedules->pluck('weekday')->unique()->all())),
            'slotMinTime' => $this->bound($schedules->min('start_time') ?? '08:00', -1),
            'slotMaxTime' => $this->bound($schedules->max('end_time') ?? '20:00', 1),
            'can' => [
                'create' => $this->canInBusiness('abs_appointment.create'),
                'reschedule' => $this->canInBusiness('abs_appointment.reschedule'),
            ],
            'statuses' => collect(AppointmentStatus::cases())->reject(fn ($s) => $s === AppointmentStatus::Expired)->values(),
        ]);
    }

    /**
     * FullCalendar feed. Range limited to 62 days so a crafted request cannot load every appointment.
     */
    public function events(Request $request, AppointmentStateMachine $stateMachine): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer'],
            'staff_id' => ['nullable', 'integer'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string'],
        ]);
        $from = CarbonImmutable::parse($data['start'])->utc();
        $to = CarbonImmutable::parse($data['end'])->utc();
        if ($from->diffInDays($to) > 62) {
            $to = $from->addDays(62);
        }

        $staffIds = array_filter(array_merge($data['staff_ids'] ?? [], [$data['staff_id'] ?? null]));
        $statuses = array_values(array_filter(array_map(fn ($s) => AppointmentStatus::tryFrom($s)?->value, $data['statuses'] ?? [])));
        $allStaff = $this->business()->staff()->withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id']);
        $can = [
            'approve' => $this->canInBusiness('abs_appointment.approve'),
            'cancel' => $this->canInBusiness('abs_appointment.cancel'),
            'status' => $this->canInBusiness('abs_appointment.status'),
            'reschedule' => $this->canInBusiness('abs_appointment.reschedule'),
        ];

        $events = $this->visibleAppointments()
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->where('status', '!=', AppointmentStatus::Expired->value)
            ->when($staffIds, fn ($q) => $q->whereIn('staff_id', $staffIds))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->with('staff:id,name', 'items:id,appointment_id,service_name')
            ->orderBy('starts_at')
            ->limit(2000)
            ->get()
            ->map(function (Appointment $a) use ($allStaff, $can, $stateMachine) {
                $color = $this->colorFor($a->staff_id, $allStaff);
                $canMove = $can['reschedule'] && in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true) && $a->starts_at->isFuture();

                return [
                    'id' => $a->id,
                    'title' => $a->customer_name,
                    'start' => $a->starts_at->toIso8601String(),
                    'end' => $a->ends_at->toIso8601String(),
                    'backgroundColor' => $this->tint($color),
                    'borderColor' => $color,
                    'textColor' => '#1f2937',
                    'classNames' => ['abs-ev', 'abs-status-'.$a->status->value],
                    'editable' => $canMove,
                    'extendedProps' => [
                        'reference' => $a->booking_reference,
                        'service' => $a->items->first()?->service_name,
                        'staff' => $a->staff?->name,
                        'staffColor' => $color,
                        'phone' => $a->customer_phone,
                        'email' => $a->customer_email,
                        'status' => $a->status->value,
                        'statusLabel' => $a->status->label(),
                        'statusColor' => $a->status->color(),
                        'payment' => $a->payment_status->label(),
                        'paymentColor' => $a->payment_status->color(),
                        'total' => $a->money($a->total_minor),
                        'outstanding' => $a->outstandingMinor() > 0 ? $a->money($a->outstandingMinor()) : null,
                        'totalMinor' => $a->total_minor,
                        'url' => route('abs.appointments.show', $a->id),
                        'actions' => $this->actions($a, $can, $stateMachine),
                    ],
                ];
            });

        return response()->json($events);
    }

    /**
     * Drag-and-drop reschedule. Same rules as the reschedule form: permission, availability under lock, history.
     */
    public function move(Request $request, int $appointment, AppointmentReschedulingAction $rescheduling): JsonResponse
    {
        $model = $this->findAppointment($appointment);
        $data = $request->validate(['start' => ['required', 'date']]);

        try {
            $rescheduling->execute($model, CarbonImmutable::parse($data['start'])->utc(), ActorType::Staff, $request->user(), null, 'Moved on calendar');
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $model->refresh();

        return response()->json([
            'message' => 'Moved to '.$model->localStartsAt()->format('D, d M h:i A').'.',
            'start' => $model->starts_at->toIso8601String(),
            'end' => $model->ends_at->toIso8601String(),
        ]);
    }

    /**
     * Status buttons the user may press now (permission + transition map + time rules).
     *
     * @param  array<string, bool>  $can
     * @return array<int, array{status: string, label: string, color: string, icon: string, needsReason: bool}>
     */
    private function actions(Appointment $appointment, array $can, AppointmentStateMachine $stateMachine): array
    {
        return collect($appointment->status->allowedTransitions())
            ->reject(fn ($s) => in_array($s, [AppointmentStatus::Expired, AppointmentStatus::Pending], true))
            ->filter(fn ($s) => match ($s) {
                AppointmentStatus::Confirmed, AppointmentStatus::Rejected => $appointment->status === AppointmentStatus::Pending ? $can['approve'] : $can['status'],
                AppointmentStatus::Cancelled => $can['cancel'],
                default => $can['status'],
            })
            ->filter(fn ($s) => $stateMachine->tooEarly($appointment, $s) === null)
            ->map(fn ($s) => ['status' => $s->value] + $s->actionMeta() + [
                'needsReason' => in_array($s, [AppointmentStatus::Cancelled, AppointmentStatus::Rejected], true),
            ])
            ->values()
            ->all();
    }

    /**
     * Stable colour per staff member (by position in the business's staff list).
     *
     * @param  Collection<int, Staff>  $staff
     */
    private function colorFor(int $staffId, Collection $staff): string
    {
        $index = $staff->search(fn ($s) => $s->id === $staffId);

        return self::PALETTE[($index === false ? $staffId : $index) % count(self::PALETTE)];
    }

    private function tint(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return "rgba({$r}, {$g}, {$b}, 0.14)";
    }

    /**
     * One hour before the earliest / after the latest working time, as FullCalendar "HH:MM:SS".
     */
    private function bound(string $time, int $hours): string
    {
        $minutes = (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2) + $hours * 60;
        $minutes = $hours < 0 ? (int) floor($minutes / 60) * 60 : (int) ceil($minutes / 60) * 60;
        $minutes = max(0, min(24 * 60, $minutes));

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
