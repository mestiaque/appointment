<?php

namespace ME\Abs\Http\Controllers\Admin\Concerns;

use Illuminate\Database\Eloquent\Builder;
use ME\Abs\Models\Appointment;
use ME\Abs\Support\BusinessAccess;

trait FindsAppointments
{
    /**
     * Appointments the user may see in the current business (staff-role members: only their own).
     *
     * @return Builder<Appointment>
     */
    protected function visibleAppointments(): Builder
    {
        $business = $this->business();
        $staffId = app(BusinessAccess::class)->restrictedStaffId(auth()->user(), $business);

        return Appointment::query()
            ->where('business_id', $business->getKey())
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId));
    }

    protected function findAppointment(int $id): Appointment
    {
        return $this->visibleAppointments()->findOrFail($id);
    }
}
