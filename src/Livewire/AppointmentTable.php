<?php

namespace ME\Abs\Livewire;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Livewire\Concerns\InteractsWithBusiness;
use ME\Abs\Models\Appointment;

class AppointmentTable extends Component
{
    use InteractsWithBusiness, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $paymentStatus = '';

    #[Url]
    public string $staffId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'paymentStatus', 'staffId', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'paymentStatus', 'staffId', 'from', 'to');
        $this->resetPage();
    }

    public function render()
    {
        $this->authorizeBusiness('abs_appointment.view');
        $business = $this->business();
        $tz = $business->timezone;
        $restricted = $this->restrictedStaffId();

        $appointments = Appointment::query()
            ->where('business_id', $business->getKey())
            ->when($restricted !== null, fn ($q) => $q->where('staff_id', $restricted))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $q->where(fn ($q) => $q->where('booking_reference', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('customer_email', 'like', $term)
                    ->orWhere('customer_phone', 'like', $term));
            })
            ->when(AppointmentStatus::tryFrom($this->status), fn ($q, $s) => $q->where('status', $s->value))
            ->when(PaymentStatus::tryFrom($this->paymentStatus), fn ($q, $s) => $q->where('payment_status', $s->value))
            ->when(ctype_digit($this->staffId), fn ($q) => $q->where('staff_id', (int) $this->staffId))
            ->when($this->validDate($this->from), fn ($q) => $q->where('starts_at', '>=', CarbonImmutable::parse($this->from, $tz)->startOfDay()->utc()))
            ->when($this->validDate($this->to), fn ($q) => $q->where('starts_at', '<', CarbonImmutable::parse($this->to, $tz)->addDay()->startOfDay()->utc()))
            ->with('staff:id,name', 'items:id,appointment_id,service_name')
            ->orderByDesc('starts_at')
            ->paginate(15);

        return view('abs::livewire.appointment-table', [
            'appointments' => $appointments,
            'staffList' => $business->staff()->withTrashed()->orderBy('name')->get(['id', 'name']),
            'statuses' => AppointmentStatus::cases(),
            'paymentStatuses' => PaymentStatus::cases(),
            'timezone' => $tz,
        ]);
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }
}
